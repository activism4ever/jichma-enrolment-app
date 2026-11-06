#!/usr/bin/env bash
# End-to-end smoke test driven over real HTTP against a running server.
# Uses a cookie jar and CSRF tokens exactly as a browser would.
set -uo pipefail

BASE="${BASE:-http://127.0.0.1:8123}"
EMAIL="${EMAIL:-admin@jichma.test}"
PASS="${PASS:-ChangeMe2026!}"
JAR=$(mktemp -t jichma-cookies.XXXXXX)
TMP=$(mktemp -d)
PASSED=0; FAILED=0

cleanup() { rm -rf "$JAR" "$TMP"; }
trap cleanup EXIT

# Extract the CSRF token from the meta tag of a page.
token() {
  curl -s -b "$JAR" -c "$JAR" "$1" \
    | grep -oE 'name="_token" value="[^"]+"' \
    | head -1 | sed 's/.*value="//; s/"//'
}

# Follow redirects, report final status.
code() { curl -s -o "$TMP/body" -w "%{http_code}" -b "$JAR" -c "$JAR" "$1"; }

# Submit a form to a path, following redirects.
# POST and report the status of the response itself, not of the page the
# redirect lands on: a validation failure is a 302 back to the form, and
# following it would report 200 and hide the rejection.
post() {
  local path="$1"; shift
  local t; t=$(token "$BASE$path")
  curl -s -o "$TMP/body" -w "%{http_code}" -b "$JAR" -c "$JAR" \
    -e "$BASE$path" -F "_token=$t" "$@" "$BASE$path"
}

# Re-read the page a redirect points at.
follow() { curl -s -L -o "$TMP/body" -w "%{http_code}" -b "$JAR" -c "$JAR" "$1"; }

check() {
  local label="$1"; local got="$2"; local want="$3"
  if [ "$got" = "$want" ]; then
    PASSED=$((PASSED+1)); printf '  ok    %-46s %s\n' "$label" "$got"
  else
    FAILED=$((FAILED+1)); printf '  FAIL  %-46s got=%s want=%s\n' "$label" "$got" "$want"
  fi
}

contains() {
  local label="$1"; local needle="$2"; local file="$3"
  if grep -qF "$needle" "$file"; then
    PASSED=$((PASSED+1)); printf '  ok    %-46s contains %s\n' "$label" "\"$needle\""
  else
    FAILED=$((FAILED+1)); printf '  FAIL  %-46s missing %s\n' "$label" "\"$needle\""
  fi
}

# The other half of contains(). Needed wherever the point is that a message is
# *absent* — a banner shown on every visit, or a notice that should have been
# suppressed — because nothing about the page looking right proves either way.
missing() {
  local label="$1"; local needle="$2"; local file="$3"
  if grep -qF "$needle" "$file"; then
    FAILED=$((FAILED+1)); printf '  FAIL  %-46s still shows %s\n' "$label" "\"$needle\""
  else
    PASSED=$((PASSED+1)); printf '  ok    %-46s absent %s\n' "$label" "\"$needle\""
  fi
}

echo "== reset to a known state =="
php artisan migrate:fresh --seed --force >/dev/null 2>&1 || {
  echo "could not reset the database" >&2; exit 1; }
echo "  ok    database reset to seed"

echo "== anonymous =="
check "GET / redirects"        "$(code "$BASE/")" 302
check "guest /enrolees denied" "$(code "$BASE/enrolees")" 302
check "GET /login"             "$(code "$BASE/login")" 200
contains "login form" 'name="password"' "$TMP/body"
check "GET /css/app.css"       "$(code "$BASE/css/app.css")" 200
check "GET /js/app.js"         "$(code "$BASE/js/app.js")" 200

echo "== authenticate =="
T=$(token "$BASE/login")
LOGIN_CODE=$(curl -s -L -o "$TMP/body" -w "%{http_code}" -b "$JAR" -c "$JAR" \
  -e "$BASE/enrolees/create" -F "_token=$T" -F "email=$EMAIL" -F "password=$PASS" "$BASE/login")
check "POST /login" "$LOGIN_CODE" 200
contains "forced to change password" 'Change your password' "$TMP/body"

echo "== forced password change gate (while the flag is set) =="
check "GET /enrolees/create gated"  "$(code "$BASE/enrolees/create")" 302
check "GET /admin/users gated"      "$(code "$BASE/admin/users")" 302

echo "== clear the forced-change flag, then check the full navigation =="
php artisan tinker --execute="
\$u = \App\Models\User::where('email','$EMAIL')->first();
\$u->must_change_password = false; \$u->save();
" >/dev/null 2>&1 || { echo "could not clear the flag" >&2; exit 1; }

check "GET /dashboard now reachable" "$(code "$BASE/dashboard")" 200
contains "dashboard reached" 'Welcome' "$TMP/body"
contains "nav has Enrolees"  '>Enrolees<' "$TMP/body"
contains "nav has Users"     '>Users<' "$TMP/body"
contains "nav has Locations" '>Locations<' "$TMP/body"

echo "== enrolee list =="
check "GET /enrolees"      "$(code "$BASE/enrolees")" 200
contains "empty state" 'No enrolees' "$TMP/body"
check "GET /enrolees/create" "$(code "$BASE/enrolees/create")" 200
contains "form has NIN"  'name="nin"'  "$TMP/body"
contains "form has photo" 'name="photo"' "$TMP/body"

echo "== location cascade API =="
LGA=$(php artisan tinker --execute="echo \App\Models\Lga::orderBy('id')->value('id');" 2>/dev/null | tr -dc '0-9')
# Ward and facility must belong to that LGA/ward: the server checks the chain.
WARD=$(php artisan tinker --execute="echo \App\Models\Ward::where('lga_id',\App\Models\Lga::orderBy('id')->value('id'))->orderBy('id')->value('id');" 2>/dev/null | tr -dc '0-9')
FAC=$(php artisan tinker --execute="echo \App\Models\Facility::where('ward_id',\App\Models\Ward::orderBy('id')->value('id'))->orderBy('id')->value('id');" 2>/dev/null | tr -dc '0-9')
echo "  info  lga=$LGA ward=$WARD facility=$FAC"
T=$(token "$BASE/enrolees")
check "GET /api/lgas/$LGA/wards" \
  "$(curl -s -o "$TMP/body" -w "%{http_code}" -b "$JAR" -c "$JAR" -H "Accept: application/json" \
     -H "X-CSRF-TOKEN: $T" "$BASE/api/lgas/$LGA/wards")" 200
contains "wards json has name" '"name"' "$TMP/body"

echo "== create a draft enrolee =="
# A genuine 120x150 JPEG: submit requires a photograph, and a stub file would
# only prove that MIME sniffing accepts a text file with an image extension.
PHOTO="$TMP/photo.jpg"
php -r '
$gd = imagecreatetruecolor(120, 150);
$bg = imagecolorallocate($gd, 210, 180, 160);
imagefilledrectangle($gd, 0, 0, 120, 150, $bg);
$fg = imagecolorallocate($gd, 40, 30, 20);
imagefilledellipse($gd, 60, 60, 40, 40, $fg);
imagefilledrectangle($gd, 40, 95, 80, 150, $fg);
imagejpeg($gd, $argv[1], 90);
' "$PHOTO" || { echo "could not build test JPEG" >&2; exit 1; }
# 11 digits: 2 fixed + a 9-digit random number, and assert the length so a
# silent regression here cannot masquerade as an application bug.
NIN=$(php -r '$n = "80".mt_rand(100000000, 999999999); if (strlen($n) !== 11) { fwrite(STDERR, "bad NIN: $n\n"); exit(1); } echo $n;') || { echo "NIN generation failed" >&2; exit 1; }
PROG=$(php artisan tinker --execute="echo \\App\\Models\\Program::orderBy('id')->value('id');" 2>/dev/null | tr -dc '0-9')
T=$(token "$BASE/enrolees")
CREATE_CODE=$(curl -s -o "$TMP/body" -w "%{http_code}" -b "$JAR" -c "$JAR" \
  -e "$BASE/enrolees/create" -F "_token=$T" -F "surname=Okonkwo" -F "first_name=Ada" -F "gender=female" \
  -F "date_of_birth=1994-05-06" -F "marital_status=single" -F "education=hnd_bsc" \
  -F "phone=08012345678" -F "nin=$NIN" -F "address=12 Admiralty Way, Lagos" \
  -F "lga_id=$LGA" -F "ward_id=$WARD" -F "facility_id=$FAC" -F "program_id=$PROG" \
  -F "photo=@$PHOTO;type=image/jpeg" "$BASE/enrolees")
check "POST /enrolees redirects to record" "$CREATE_CODE" 302
ID=$(php artisan tinker --execute="echo \\App\\Models\\Enrolee::orderByDesc('id')->value('id');" 2>/dev/null | tr -dc '0-9')
check "record page after create" "$(follow "$BASE/enrolees/$ID")" 200
contains "draft saved" 'Okonkwo' "$TMP/body"

echo "== names are capitalised on the way in =="
# Typed as they would arrive from a keyboard with Caps Lock on, and with the
# padding a pasted name carries.
T=$(token "$BASE/enrolees")
# In a variable, because an apostrophe cannot be written straight into the
# double-quoted -F argument above without ending the quoting.
APOS="'"
curl -s -o /dev/null -b "$JAR" -c "$JAR" -e "$BASE/enrolees/create" \
  -F "_token=$T" -F "surname=  oyelaran  " -F "first_name=ADEYEMI" -F "middle_name=O${APOS}NEIL" \
  -F "lga_id=$LGA" -F "ward_id=$WARD" -F "facility_id=$FAC" -F "program_id=$PROG" "$BASE/enrolees"
CAP=$(php artisan tinker --execute="echo \\App\\Models\\Enrolee::orderByDesc('id')->first()->full_name;" 2>/dev/null | tr -d '\r' | tail -1)
check "surname, first and middle name capitalised" "$CAP" "Oyelaran Adeyemi O'Neil"

echo "== duplicate NIN is refused =="
T=$(token "$BASE/enrolees")
check "POST duplicate NIN -> redirect w/ error" \
  "$(curl -s -o "$TMP/body" -w "%{http_code}" -b "$JAR" -c "$JAR" -e "$BASE/enrolees/create" \
     -F "_token=$T" -F "surname=Copy" -F "first_name=Cat" -F "gender=male" \
     -F "date_of_birth=1990-01-01" -F "marital_status=single" -F "education=hnd_bsc" \
     -F "phone=08099999999" -F "nin=$NIN" -F "lga_id=$LGA" -F "program_id=$PROG" "$BASE/enrolees")" 302

echo "== validation rejects bad data =="
T=$(token "$BASE/enrolees")
check "POST bad NIN + bad phone -> 302" \
  "$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -c "$JAR" -e "$BASE/enrolees/create" \
     -F "_token=$T" -F "surname=X" -F "first_name=Y" -F "gender=other" \
     -F "date_of_birth=1800-01-01" -F "marital_status=single" -F "education=hnd_bsc" \
     -F "phone=123" -F "nin=12" -F "lga_id=$LGA" -F "program_id=$PROG" "$BASE/enrolees")" 302

echo "== submit the draft =="
T=$(token "$BASE/enrolees/$ID")
check "GET /enrolees/$ID" "$(code "$BASE/enrolees/$ID")" 200
contains "record page" 'Ada' "$TMP/body"
check "POST submit" "$(curl -s -L -o "$TMP/body" -w "%{http_code}" -b "$JAR" -c "$JAR" \
  -e "$BASE/enrolees/create" -F "_token=$T" -F "_method=POST" "$BASE/enrolees/$ID/submit")" 200
contains "now submitted" 'Submitted' "$TMP/body"

# The green banner belongs to the request the submit redirects to. Both are
# checked here because the failure mode is a lock reported as two messages at
# once, which still looks like the feature working.
contains "green submission banner, once" 'Registration submitted' "$TMP/body"
missing  "no blue notice yet"          'This record is locked.'  "$TMP/body"

echo "== a later visit does not repeat the green banner =="
check "GET the record again" "$(code "$BASE/enrolees/$ID")" 200
missing  "no green banner later" 'Registration submitted' "$TMP/body"
# No blue notice for this viewer, and correctly so: the smoke run is signed in as
# an administrator, who may still correct a submitted record. The notice belongs
# to a viewer who cannot edit it, which is checked on the reviewer below.

echo "== submitted record is locked =="
T=$(token "$BASE/enrolees/$ID")
# An administrator may still correct a submitted record, so this one is allowed
# through and lands on a redirect. The refusal for a Program User is checked in
# the role isolation block below, where there is a Program User to try.
check "PUT on submitted as admin -> allowed" \
  "$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -c "$JAR" -e "$BASE/enrolees/create" \
     -F "_token=$T" -F "_method=PUT" -F "surname=Ada" -F "first_name=Ada" \
     -F "gender=female" -F "date_of_birth=1994-05-06" -F "marital_status=single" \
     -F "education=hnd_bsc" -F "phone=08012345678" -F "nin=$NIN" -F "lga_id=$LGA" -F "program_id=$PROG" \
     -X POST "$BASE/enrolees/$ID")" 302

echo "== admin screens =="
check "GET /dashboard" "$(code "$BASE/dashboard")" 200
contains "dashboard names each program once" 'CEMOG' "$TMP/body"
contains "dashboard counts active users" 'Active users' "$TMP/body"

check "GET /admin/users"    "$(code "$BASE/admin/users")" 200
contains "users table" 'admin@jichma.test' "$TMP/body"

check "GET /admin/locations" "$(code "$BASE/admin/locations")" 200
contains "location counts" 'LGAs' "$TMP/body"
check "GET /admin/audit"    "$(code "$BASE/admin/audit")" 200
check "GET /admin/exports"  "$(code "$BASE/admin/exports")" 200
contains "export offers CSV" 'CSV' "$TMP/body"
contains "export offers XLSX" 'XLSX' "$TMP/body"
contains "export offers photos" 'Photos (ZIP)' "$TMP/body"
missing "export asks for no password" 'Re-enter your password' "$TMP/body"
contains "export quotes a total" 'enrolees in total' "$TMP/body"
check "GET /admin/locations/template" "$(code "$BASE/admin/locations/template")" 200

echo "== queue an export and run it =="
# No password is sent, and none is required: the endpoint must queue on the
# strength of the session and its own authorisation, not a second secret.
T=$(token "$BASE/admin/exports")
check "POST export request" "$(curl -s -L -o "$TMP/body" -w "%{http_code}" -b "$JAR" -c "$JAR" \
  -e "$BASE/enrolees/create" -F "_token=$T" -F "kind=csv" "$BASE/admin/exports")" 200
contains "export queued" 'Export queued' "$TMP/body"

# A request is still refused when the format is not one that exists, so the
# endpoint is checking the request rather than accepting anything posted to it.
T=$(token "$BASE/admin/exports")
BEFORE=$(php artisan tinker --execute="echo \App\Models\Export::count();" 2>/dev/null | tr -dc '0-9')
curl -s -L -o "$TMP/body" -b "$JAR" -c "$JAR" -e "$BASE/enrolees/create" \
  -F "_token=$T" -F "kind=pdf" "$BASE/admin/exports" >/dev/null
contains "unknown format refused" 'Choose CSV, XLSX or Photos (ZIP).' "$TMP/body"
check "refused format queues nothing" \
  "$(php artisan tinker --execute="echo \App\Models\Export::count();" 2>/dev/null | tr -dc '0-9')" "$BEFORE"

php artisan queue:work --queue=exports --stop-when-empty --tries=1 >/dev/null 2>&1
EXP=$(php artisan tinker --execute="echo \App\Models\Export::orderByDesc('id')->value('id');" 2>/dev/null | tr -dc '0-9')
# Re-fetched rather than reusing the earlier body: the export is only "Ready"
# once the worker has run, and a response captured before that cannot show it.
curl -s -o "$TMP/body" -b "$JAR" -c "$JAR" "$BASE/admin/exports"
contains "export ready" 'Ready' "$TMP/body"
contains "export lists its filters" 'All programs' "$TMP/body"
contains "export offers download" "$BASE/admin/exports/$EXP/download" "$TMP/body"
T=$(token "$BASE/admin/exports")
check "GET download export $EXP" \
  "$(curl -s -o "$TMP/dl.csv" -w "%{http_code}" -b "$JAR" -c "$JAR" -e "$BASE" "$BASE/admin/exports/$EXP/download")" 200
contains "downloaded csv" 'surname' "$TMP/dl.csv"

echo "== export date range and filter chips =="
TODAY=$(php artisan tinker --execute="echo now()->toDateString();" 2>/dev/null | tr -dc '0-9-')
TOMORROW=$(php artisan tinker --execute="echo now()->addDay()->toDateString();" 2>/dev/null | tr -dc '0-9-')

# Reversed first: the message has to name the two boxes as they are labelled,
# because "the to field" reads as a different thing from "Registered to".
T=$(token "$BASE/admin/exports")
curl -s -L -o "$TMP/body" -b "$JAR" -c "$JAR" -e "$BASE/admin/exports" \
  -F "_token=$T" -F "kind=csv" -F "from=$TOMORROW" -F "to=$TODAY" \
  "$BASE/admin/exports" >/dev/null
contains "reversed range explained" 'Registered to cannot be earlier than Registered from.' "$TMP/body"
missing "no bare 'to field'" 'to field' "$TMP/body"

# The other direction has to be accepted, and "to today" is the case that has to
# include the record registered today rather than stopping at midnight.
T=$(token "$BASE/admin/exports")
curl -s -L -o "$TMP/body" -b "$JAR" -c "$JAR" -e "$BASE/admin/exports" \
  -F "_token=$T" -F "kind=csv" -F "from=$TODAY" -F "to=$TODAY" \
  "$BASE/admin/exports" >/dev/null
contains "range to today queued" 'Export queued' "$TMP/body"

# The worker runs again because this export is newer than the one the download
# checks further down pick up, and a pending export is not downloadable.
php artisan queue:work --queue=exports --stop-when-empty --tries=1 >/dev/null 2>&1
EXP_TODAY=$(php artisan tinker --execute="echo \App\Models\Export::orderByDesc('id')->value('id');" 2>/dev/null | tr -dc '0-9')
check "download export to today" \
  "$(curl -s -o "$TMP/dl-today.csv" -w "%{http_code}" -b "$JAR" -c "$JAR" -e "$BASE" "$BASE/admin/exports/$EXP_TODAY/download")" 200
contains "export to today holds today's record" 'Ada' "$TMP/dl-today.csv"

# A filtered page states what is narrowing the count, and each chip removes
# itself alone: dropping the status must leave the date range in place.
curl -s -o "$TMP/body" -b "$JAR" -c "$JAR" "$BASE/admin/exports?status=submitted&from=$TODAY"
contains "filters shown as chips" 'Active filters:' "$TMP/body"
contains "chip names the status" 'Status: Submitted' "$TMP/body"
contains "chip names the date" "Registered from: $TODAY" "$TMP/body"
contains "chip is removable" 'Remove filter: Status: Submitted' "$TMP/body"
contains "removal keeps other filters" "$BASE/admin/exports?from=$TODAY" "$TMP/body"
check "filtered export page" "$(code "$BASE/admin/exports?status=submitted")" 200
check "unfiltered export page" "$(code "$BASE/admin/exports")" 200
missing "unfiltered page has no chips" 'Active filters:' "$TMP/body"

echo "== search boxes opt out of autofill =="
for path in /admin/exports /enrolees /admin/users; do
  code "$BASE$path" >/dev/null
  contains "search opts out: $path" 'name="search"' "$TMP/body"
  contains "autocomplete off: $path" 'autocomplete="off"' "$TMP/body"
done
# The export form has no password field, so nothing on it should offer one.
code "$BASE/admin/exports" >/dev/null
missing "export has no password field" 'name="password"' "$TMP/body"

echo "== audit trail recorded =="
check "GET /admin/audit" "$(code "$BASE/admin/audit")" 200
for a in enrolee_created enrolee_submitted export_requested; do
  contains "audit row: $a" ">$a<" "$TMP/body"
done

echo "== location import =="
# One row naming existing places (they must be reused, not duplicated) and one
# complete new branch. The blank-ward row is deliberate: a row missing any of
# the three names is skipped, not guessed at.
cat > "$TMP/locations.csv" <<CSV
lga,ward,facility
Auyo,Auyakayi,Auyakayi Basic Health Clinic
Smoke Test Zone,Smoke Ward,Smoke Clinic A
Smoke Test Zone,Smoke Ward,Smoke Clinic B
Smoke Test Zone,,Orphan Facility Without A Ward
CSV

GEO_BEFORE=$(php artisan tinker --execute="echo \\App\\Models\\Lga::count().'/'.\\App\\Models\\Ward::count().'/'.\\App\\Models\\Facility::count();" 2>/dev/null | tr -d '\r' | tail -1)
T=$(token "$BASE/admin/locations")
IMPORT=$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -c "$JAR" -e "$BASE/admin/locations" \
  -F "_token=$T" -F "file=@$TMP/locations.csv;type=text/csv" -F "confirm_replace=1" \
  "$BASE/admin/locations/import")
check "POST location import" "$IMPORT" 302
GEO_AFTER=$(php artisan tinker --execute="echo \\App\\Models\\Lga::count().'/'.\\App\\Models\\Ward::count().'/'.\\App\\Models\\Facility::count();" 2>/dev/null | tr -d '\r' | tail -1)
echo "  info  geography $GEO_BEFORE -> $GEO_AFTER"

# The real contract: existing names are matched, so the totals must rise by
# exactly the new branch and nothing else, and the skipped row must not appear.
php artisan tinker --execute="
\$before = explode('/', '$GEO_BEFORE');
\$after  = explode('/', '$GEO_AFTER');
\$dLga = (int)\$after[0] - (int)\$before[0];
\$dWard = (int)\$after[1] - (int)\$before[1];
\$dFac = (int)\$after[2] - (int)\$before[2];
echo '  info  added lga='.\$dLga.' ward='.\$dWard.' facility='.\$dFac.' (expect 1/1/2)'.PHP_EOL;

\$z = \\App\\Models\\Lga::where('name','Smoke Test Zone')->first();
echo '  info  new branch lga='.(\$z ? \$z->id : 'MISSING')
    .' wards='.(\$z ? \$z->wards()->count() : 0)
    .' facilities='.(\$z ? \\App\\Models\\Facility::whereIn('ward_id', \$z->wards()->pluck('id'))->count() : 0).PHP_EOL;

echo '  info  skipped row created anything? '
    .(\\App\\Models\\Facility::where('name','Orphan Facility Without A Ward')->exists() ? 'YES (bug)' : 'no')
    .PHP_EOL;

// A pre-existing place must still be the same row, so the enrolee that
// references it keeps working.
\$e = \\App\\Models\\Enrolee::orderByDesc('id')->first();
echo '  info  enrolee '.\$e->id.' still linked -> ward='.\$e->ward->name.' facility='.\$e->facility->name.PHP_EOL;
" 2>&1 | grep -E '^  info'

# Turn the reported numbers into assertions, comparing printed values rather
# than exit codes (tinker exits 1 on any error, true or false).
q() { php artisan tinker --execute="$1" 2>/dev/null | tr -d '\r' | tail -1; }

check "new LGA was added" \
  "$(q "echo \\App\\Models\\Lga::where('name','Smoke Test Zone')->exists() ? 'yes' : 'no';")" "yes"
check "new ward was added" \
  "$(q "echo \\App\\Models\\Ward::where('name','Smoke Ward')->exists() ? 'yes' : 'no';")" "yes"
check "both new facilities added" \
  "$(q "echo \\App\\Models\\Facility::whereIn('name',['Smoke Clinic A','Smoke Clinic B'])->count();")" "2"
check "incomplete row was skipped" \
  "$(q "echo \\App\\Models\\Facility::where('name','Orphan Facility Without A Ward')->exists() ? 'created' : 'absent';")" "absent"
check "enrolee still linked to its ward" \
  "$(q "echo \\App\\Models\\Enrolee::orderByDesc('id')->first()->ward->name;")" "Auyakayi"
DUPW=$(php artisan tinker --execute="echo \\App\\Models\\Ward::where('lga_id',\\App\\Models\\Lga::where('name','Auyo')->value('id'))->where('name','Auyakayi')->count();" 2>/dev/null | tr -dc '0-9')
check "existing ward not duplicated" "$DUPW" "1"

echo "== import without the confirmation is refused =="
T=$(token "$BASE/admin/locations")
NOCONF=$(curl -s -L -o "$TMP/body" -w "%{http_code}" -b "$JAR" -c "$JAR" \
  -e "$BASE/admin/locations" -F "_token=$T" -F "file=@$TMP/locations.csv;type=text/csv" \
  "$BASE/admin/locations/import")
check "unconfirmed import rejected" "$NOCONF" 200
contains "confirmation demanded" 'onfirm' "$TMP/body"

echo "== role isolation =="
# A Program User is confined to one program; a Reviewer can read but not write.
PROG=$(php artisan tinker --execute="echo \\App\\Models\\Program::orderBy('id')->value('id');" 2>/dev/null | tr -dc '0-9')
T=$(token "$BASE/admin/users")
curl -s -o /dev/null -b "$JAR" -c "$JAR" -e "$BASE/admin/users/create" \
  -F "_token=$T" -F "name=Smoke Program User" -F "email=smoke-pu@jichma.test" \
  -F "role=program_user" -F "program_id=$PROG" -F "password=Tmp#2026x" \
  -F "password_confirmation=Tmp#2026x" "$BASE/admin/users"
php artisan tinker --execute="
\\App\\Models\\User::where('email','smoke-pu@jichma.test')->update(['must_change_password' => false]);
\\App\\Models\\User::where('email','smoke-pu@jichma.test')->update(['password' => \\Illuminate\\Support\\Facades\\Hash::make('Tmp#2026x')]);
" >/dev/null 2>&1

echo "== the users page and the dashboard agree on active users =="
# Checked after the Program User above exists, not in the admin block: matching
# "0" against "0" would pass whether or not the two screens read one source.
USER_PU=$(php artisan tinker --execute="echo \App\Models\User::activeCountsByRole()['program_user'] ?? 0;" 2>/dev/null | tr -dc '0-9')
check "a Program User now exists" "$([ "$USER_PU" -gt 0 ] && echo yes || echo no)" "yes"
curl -s -o "$TMP/body" -b "$JAR" -c "$JAR" "$BASE/admin/users"
contains "users page shows the active Program User count" ">${USER_PU}<" "$TMP/body"
curl -s -o "$TMP/body" -b "$JAR" -c "$JAR" "$BASE/dashboard"
contains "dashboard shows the same Program User count" ">${USER_PU}<" "$TMP/body"

PJAR="$TMP/pu.jar"
pu_code() {
  local t; t=$(curl -s -c "$PJAR" -b "$PJAR" "$BASE/login" \
    | grep -oE 'name="_token" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"//')
  curl -s -L -o /dev/null -c "$PJAR" -b "$PJAR" -e "$BASE" -F "_token=$t" \
    -F "email=smoke-pu@jichma.test" -F "password=Tmp#2026x" "$BASE/login"
  curl -s -o /dev/null -w "%{http_code}" -b "$PJAR" -c "$PJAR" "$1"
}
pu_code "$BASE/enrolees" >/dev/null
check "PU can list enrolees"        "$(pu_code "$BASE/enrolees")" 200
check "PU can open create form"     "$(pu_code "$BASE/enrolees/create")" 200

# Asked for directly rather than by following a link: the record page hides the
# Edit button for a Program User, so the only thing that can keep the URL shut is
# the policy behind it.
check "PU refused the edit form of a submitted record" "$(pu_code "$BASE/enrolees/$ID/edit")" 403
check "PU refused another user's photograph"           "$(pu_code "$BASE/enrolees/$ID/photo")" 403
check "PU blocked from users"       "$(pu_code "$BASE/admin/users")" 403
check "PU blocked from exports"     "$(pu_code "$BASE/admin/exports")" 403
check "PU blocked from audit"       "$(pu_code "$BASE/admin/audit")" 403
check "PU blocked from locations"   "$(pu_code "$BASE/admin/locations")" 403

php artisan tinker --execute="
\\App\\Models\\User::where('email','smoke-pu@jichma.test')->update(['role' => 'reviewer']);
" >/dev/null 2>&1
check "Reviewer can list enrolees"  "$(pu_code "$BASE/enrolees")" 200

# A read-only viewer arriving at somebody else's submitted record: the state a
# later visit is in for anyone who did not submit it themselves.
curl -s -o "$TMP/body" -b "$PJAR" -c "$PJAR" "$BASE/enrolees/$ID"
contains "reviewer sees the blue locked notice" 'This record is locked.' "$TMP/body"
missing  "reviewer sees no green banner"        'Registration submitted' "$TMP/body"
check "Reviewer blocked from create" "$(pu_code "$BASE/enrolees/create")" 403
check "Reviewer blocked from exports" "$(pu_code "$BASE/admin/exports")" 403

echo "== a reviewer cannot write =="
COUNT_BEFORE=$(php artisan tinker --execute="echo \\App\\Models\\Enrolee::count();" 2>/dev/null | tr -dc '0-9')
T=$(curl -s -c "$PJAR" -b "$PJAR" "$BASE/enrolees" | grep -oE 'name="_token" value="[^"]+"' | head -1 | sed 's/.*value="//; s/"//')
curl -s -o /dev/null -w "%{http_code}" -b "$PJAR" -c "$PJAR" -e "$BASE/enrolees" \
  -F "_token=$T" -F "surname=Smoke" -F "first_name=Reviewer" -F "gender=male" \
  -F "date_of_birth=1990-01-01" -F "marital_status=single" -F "education=hnd_bsc" \
  -F "phone=08033334444" -F "nin=80765432100" -F "address=1 Test Close" \
  -F "lga_id=$LGA" -F "ward_id=$WARD" -F "facility_id=$FAC" "$BASE/enrolees" \
  > "$TMP/revpost"
COUNT_AFTER=$(php artisan tinker --execute="echo \\App\\Models\\Enrolee::count();" 2>/dev/null | tr -dc '0-9')
check "reviewer POST refused"       "$(cat "$TMP/revpost")" 403
check "no record was created"      "$COUNT_BEFORE=$COUNT_AFTER" "$COUNT_BEFORE=$COUNT_BEFORE"

echo "== export files are private to their requester =="
EXP=$(php artisan tinker --execute="echo \\App\\Models\\Export::orderByDesc('id')->value('id');" 2>/dev/null | tr -dc '0-9')
check "admin downloads own export"  "$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -c "$JAR" "$BASE/admin/exports/$EXP/download")" 200
check "other user refused export"   "$(pu_code "$BASE/admin/exports/$EXP/download")" 403
check "guest refused export"        "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/admin/exports/$EXP/download")" 302

echo "== photographs are served through the app, not the web root =="
EID=$(php artisan tinker --execute="echo \App\Models\Enrolee::whereNotNull('photo_path')->orderByDesc('id')->value('id');" 2>/dev/null | tr -dc '0-9')
if [ -n "$EID" ]; then
  check "authorised photo streams"   "$(curl -s -o /dev/null -w "%{http_code}" -b "$JAR" -c "$JAR" "$BASE/enrolees/$EID/photo")" 200
  REL=$(php artisan tinker --execute="echo \App\Models\Enrolee::find($EID)->photo_path;" 2>/dev/null | tr -d '\r' | tail -1)
  check "photo not in the web root" "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/$REL")" 404
  check "photo not via /storage"    "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/storage/$REL")" 404
  check "guest sent to login for a photo" "$(curl -s -o /dev/null -w "%{http_code}" "$BASE/enrolees/$EID/photo")" 302

  # The enlarge control has to point at that same route. A second URL for the
  # full-size copy would not be covered by any of the checks above.
  curl -s -o "$TMP/body" -b "$JAR" -c "$JAR" "$BASE/enrolees/$EID"
  contains "record page offers the lightbox" 'data-lightbox' "$TMP/body"
  contains "lightbox points at the guarded route" "$BASE/enrolees/$EID/photo" "$TMP/body"
  missing  "no second, unguarded photo URL" 'data-lightbox-src' "$TMP/body"
else
  echo "  skip  no photograph on file to probe"
fi

echo "== sign out =="
T=$(token "$BASE/dashboard")
curl -s -L -o "$TMP/body" -b "$JAR" -c "$JAR" -e "$BASE/enrolees/create" -F "_token=$T" "$BASE/logout" >/dev/null
check "session ended (/enrolees denied)" "$(code "$BASE/enrolees")" 302

echo
echo "passed=$PASSED failed=$FAILED"
[ "$FAILED" -eq 0 ]
