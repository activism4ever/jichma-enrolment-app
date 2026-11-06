/* ===================================================================
   JICHMA Enrolment — application scripts

   Vanilla JS, no framework and no build step, so that the file can be
   served as-is from shared hosting with no npm and no bundler. Keeping
   it framework-free also lets the Content-Security-Policy stay strict:
   there is no 'unsafe-eval' and no inline script anywhere.
   =================================================================== */

(function () {
    'use strict';

    /* ---------------------------------------------------------------
       Mobile navigation toggle
       --------------------------------------------------------------- */
    function initNavToggle() {
        var toggle = document.querySelector('[data-nav-toggle]');
        var panel = document.querySelector('[data-nav-panel]');

        if (!toggle || !panel) return;

        toggle.addEventListener('click', function () {
            var open = panel.classList.toggle('is-open');
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    /* ---------------------------------------------------------------
       Prevent double submission

       Disables every submit button in the form and swaps in a "working"
       label, so a slow upload on a phone cannot create two records.

       All of them, not just the first: the enrolee form has two, and a second
       press on either one would be a second record.
       --------------------------------------------------------------- */
    function initSubmitGuards() {
        document.querySelectorAll('form[data-disable-on-submit]').forEach(function (form) {
            form.addEventListener('submit', function () {
                form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                    button.classList.add('is-loading');

                    var label = button.querySelector('[data-submit-label]');

                    if (label && !button.dataset.originalLabel) {
                        button.dataset.originalLabel = label.textContent;
                        label.textContent = 'Working…';
                    }
                });

                // Disabled on the next tick, not inline: a button disabled
                // during its own click handler can stop the form submitting,
                // and the clicked button's value still has to go out with it.
                setTimeout(function () {
                    form.querySelectorAll('button[type="submit"]').forEach(function (button) {
                        button.disabled = true;
                    });
                }, 0);
            });
        });
    }

    /* ---------------------------------------------------------------
       Confirmation before submitting a registration

       Attaches to the button rather than the form, because the same form also
       carries "Save draft", which must never ask. Three outcomes:

         invalid  - the browser blocks the submission and reports the bad
                    fields; nothing was locked, so the buttons stay usable.
         declined - the user said no; the submission is cancelled and the
                    buttons are put back exactly as they were.
         confirmed- both buttons are locked for the trip to the server, so a
                    double tap or an impatient second click cannot submit
                    twice.
       --------------------------------------------------------------- */
    function initSubmitConfirmations() {
        document.querySelectorAll('button[data-confirm-submit]').forEach(function (button) {
            var form = button.form;
            if (!form) return;

            var locked = false;

            function release() {
                locked = false;

                form.querySelectorAll('button[type="submit"]').forEach(function (each) {
                    each.disabled = false;
                });
            }

            button.addEventListener('click', function (event) {
                if (locked) {
                    event.preventDefault();
                    return;
                }

                // Report the problem in the browser rather than sending a
                // request that is certain to be rejected.
                if (!form.checkValidity()) {
                    release();
                    return;
                }

                if (!window.confirm(button.getAttribute('data-confirm-submit'))) {
                    event.preventDefault();
                    release();
                    return;
                }

                locked = true;

                setTimeout(function () {
                    form.querySelectorAll('button[type="submit"]').forEach(function (each) {
                        each.disabled = true;
                    });
                }, 0);
            });

            // Returning to the page with the browser's back button restores the
            // DOM but not this flag.
            window.addEventListener('pageshow', release);
        });
    }

    /* ---------------------------------------------------------------
       Cascading location dropdowns: LGA -> ward -> facility
       --------------------------------------------------------------- */
    function initLocationCascades() {
        // The root is the fieldset/form, not a select: selects also carry
        // data-cascade="lga|ward|facility" as their role marker.
        var root = document.querySelector('form[data-cascade], fieldset[data-cascade]');
        if (!root) return;

        var csrf = document.querySelector('meta[name="csrf-token"]');
        var token = csrf ? csrf.getAttribute('content') : '';

        var lgaSel = root.querySelector('[data-cascade="lga"]');
        var wardSel = root.querySelector('[data-cascade="ward"]');
        // Optional. The registration form narrows all three levels; the
        // administrator's add-facility form has no facility dropdown, because
        // the point of the form is to name a new one.
        var facilitySel = root.querySelector('[data-cascade="facility"]');

        if (!lgaSel || !wardSel) return;

        var wardError = root.querySelector('[data-error-for="ward_id"]');
        var facilityError = root.querySelector('[data-error-for="facility_id"]');

        function setOptions(select, items, placeholder, selected) {
            select.innerHTML = '';

            var blank = document.createElement('option');
            blank.value = '';
            blank.textContent = placeholder;
            select.appendChild(blank);

            items.forEach(function (item) {
                var opt = document.createElement('option');
                opt.value = String(item.id);
                opt.textContent = item.name;
                if (selected !== undefined && String(selected) === String(item.id)) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            });
        }

        function setBusy(select, busy) {
            select.disabled = busy;
        }

        function clearError(el) {
            if (el) el.textContent = '';
        }

        // Changing a parent always resets its children.
        function resetWards(message) {
            setOptions(wardSel, [], message || 'Select an LGA first');
            if (facilitySel) {
                setOptions(facilitySel, [], 'Select a ward first');
            }
            clearError(wardError);
            clearError(facilityError);
        }

        function fetchJson(url) {
            return fetch(url, {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                credentials: 'same-origin'
            }).then(function (res) {
                if (!res.ok) throw new Error('Request failed');
                return res.json();
            });
        }

        lgaSel.addEventListener('change', function () {
            resetWards();
            var lgaId = lgaSel.value;
            if (!lgaId) return;

            setBusy(wardSel, true);
            fetchJson('/api/lgas/' + encodeURIComponent(lgaId) + '/wards')
                .then(function (wards) {
                    setOptions(wardSel, wards, 'Select a ward');
                })
                .catch(function () {
                    setOptions(wardSel, [], 'Could not load wards');
                })
                .finally(function () {
                    setBusy(wardSel, false);
                });
        });

        wardSel.addEventListener('change', function () {
            // Only the registration form has a facility dropdown to narrow.
            if (!facilitySel) return;

            setOptions(facilitySel, [], 'Select a ward first');
            clearError(facilityError);

            var wardId = wardSel.value;
            if (!wardId) return;

            setBusy(facilitySel, true);
            fetchJson('/api/wards/' + encodeURIComponent(wardId) + '/facilities')
                .then(function (facilities) {
                    setOptions(facilitySel, facilities, 'Select a health facility');
                })
                .catch(function () {
                    setOptions(facilitySel, [], 'Could not load facilities');
                })
                .finally(function () {
                    setBusy(facilitySel, false);
                });
        });
    }

    /* ---------------------------------------------------------------
       Photograph picker

       Two visible buttons, one real form input. "Take photo" opens a
       camera (capture="user" asks for the front one); "Choose from files"
       opens the ordinary file browser. Whichever is used, the file is
       copied into the real input via DataTransfer so the form submits
       exactly as it would with a plain file field.

       The copy is a convenience, not a security boundary. Every check here
       is duplicated on the server: the true MIME type is sniffed with
       finfo, the image is re-encoded and resized, and EXIF is stripped.
       A user who bypasses this script still cannot smuggle anything past
       PhotoService.
       --------------------------------------------------------------- */
    var MAX_PHOTO_BYTES = 5 * 1024 * 1024;
    var ALLOWED_PHOTO_TYPES = ['image/jpeg', 'image/png'];

    function initPhotoPicker() {
        var picker = document.querySelector('[data-photo-picker]');
        var input = document.querySelector('[data-photo-input]');
        var preview = document.querySelector('[data-photo-preview]');
        if (!picker || !input || !preview) return;

        var cameraInput = picker.querySelector('[data-photo-camera-input]');
        var fileInput = picker.querySelector('[data-photo-browser-input]');
        var cameraButton = picker.querySelector('[data-photo-camera-trigger]');
        var fileButton = picker.querySelector('[data-photo-file-trigger]');
        var cameraWrapper = picker.querySelector('[data-camera-wrapper]');
        var errorBox = picker.querySelector('[data-photo-error]');

        if (!cameraInput || !fileInput || !cameraButton || !fileButton) return;

        // "Remove / change" clears the real input, which is what makes the
        // photograph genuinely absent rather than still queued for upload.
        // Declared before render() because render() toggles it.
        var removeButton = document.createElement('button');
        removeButton.type = 'button';
        removeButton.className = 'btn btn-link photo-remove';
        removeButton.textContent = 'Remove / change photo';
        removeButton.hidden = true;

        removeButton.addEventListener('click', function () {
            input.value = '';
            cameraInput.value = '';
            fileInput.value = '';
            showError('');
            render(null);
            fileButton.focus();
        });

        if (preview.parentNode) {
            preview.parentNode.insertBefore(removeButton, preview.nextSibling);
        }

        // Nothing is selected yet, so there is nothing to remove or change. A
        // record that already holds a photograph is the exception: the button is
        // useful immediately, because choosing a new file replaces it.
        removeButton.hidden = !picker.hasAttribute('data-has-existing-photo');

        // Without a camera, offering "Take photo" would only open an empty
        // picker. Two independent checks because neither is reliable alone:
        // touch-capable excludes most desktops, and the mediaDevices probe
        // confirms an actual camera exists.
        if (!hasUsableCamera()) {
            cameraWrapper.hidden = true;
        }

        cameraButton.addEventListener('click', function () {
            cameraInput.click();
        });

        fileButton.addEventListener('click', function () {
            fileInput.click();
        });

        [cameraInput, fileInput].forEach(function (source) {
            source.addEventListener('change', function () {
                var file = source.files && source.files[0];
                if (!file) return;

                var problem = validatePhoto(file);

                if (problem) {
                    showError(problem);
                    // Clear both the source and the real input, so a rejected
                    // file is never left sitting in the form ready to submit.
                    source.value = '';
                    input.value = '';
                    render(null);
                    return;
                }

                if (copyIntoRealInput(file)) {
                    render(file);
                } else {
                    render(null);
                }
            });
        });

        // A file chosen on the real input directly (JavaScript off, or the
        // input was reached some other way) still gets checked and previewed.
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];

            if (!file) {
                render(null);
                return;
            }

            var problem = validatePhoto(file);

            if (problem) {
                showError(problem);
                input.value = '';
                render(null);
                return;
            }

            showError('');
            render(file);
        });

        function validatePhoto(file) {
            if (ALLOWED_PHOTO_TYPES.indexOf(file.type) === -1) {
                return 'Choose a JPG or PNG image.';
            }

            if (file.size > MAX_PHOTO_BYTES) {
                return 'That image is larger than 5 MB. Please choose a smaller one.';
            }

            return '';
        }

        function copyIntoRealInput(file) {
            // DataTransfer is the only way to place a File into another input
            // programmatically. Where it is unavailable the real input cannot be
            // filled in, so say so instead of showing a preview that would not
            // actually submit.
            if (typeof DataTransfer !== 'function') {
                showError(
                    'This browser cannot attach the chosen image automatically. ' +
                        'Please use a different browser to add a photograph.'
                );
                input.value = '';
                return false;
            }

            try {
                var transfer = new DataTransfer();
                transfer.items.add(file);
                input.files = transfer.files;
                showError('');
                return true;
            } catch (e) {
                input.value = '';
                showError('That image could not be attached. Please choose it again.');
                return false;
            }
        }

        function showError(message) {
            if (!errorBox) return;

            errorBox.textContent = message;

            if (message) {
                errorBox.hidden = false;
            } else {
                errorBox.hidden = true;
            }
        }

        function render(file) {
            preview.innerHTML = '';

            if (!file) {
                removeButton.hidden = true;
                return;
            }

            var reader = new FileReader();

            reader.onload = function (e) {
                var img = document.createElement('img');
                img.src = e.target.result;
                img.alt = 'Selected photograph preview';
                preview.appendChild(img);

                var caption = document.createElement('p');
                caption.className = 'photo-meta';
                caption.textContent = file.name + ' — ' + Math.round(file.size / 1024) + ' KB';
                preview.appendChild(caption);

                removeButton.hidden = false;
            };

            reader.readAsDataURL(file);
        }
    }

    function hasUsableCamera() {
        var touchCapable = (
            'ontouchstart' in window ||
            (navigator.maxTouchPoints || 0) > 0
        );

        if (!touchCapable) return false;

        // The mediaDevices probe only answers over a secure context, which is
        // correct here: over plain http there is no way to ask, so the file
        // picker is offered rather than a button that may do nothing.
        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
            return false;
        }

        return true;
    }

    /* ---------------------------------------------------------------
       Confirmation for destructive actions
       --------------------------------------------------------------- */
    function initConfirmations() {
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        });
    }

    /* ---------------------------------------------------------------
       User form: show the program field only for roles that have one

       An Administrator is not attached to a program; Program User and
       Reviewer each belong to exactly one. The server enforces that
       pairing, so this only saves the user a field they must not fill in.
       --------------------------------------------------------------- */
    function initRoleProgramToggle() {
        var roleSelect = document.querySelector('[data-role-select]');
        var programField = document.querySelector('[data-program-field]');
        if (!roleSelect || !programField) return;

        function sync() {
            programField.hidden = roleSelect.value === 'admin';
        }

        roleSelect.addEventListener('change', sync);
        sync();
    }

    /* ---------------------------------------------------------------
       NIN field: digits only, with a live counter

       The input already carries inputmode="numeric", maxlength="11" and
       pattern="\d{11}". This strips anything that is not a digit as it is
       typed and updates the "x/11" readout, so a pasted NIN full of spaces
       or dashes is cleaned up rather than rejected after the fact.

       The server regex remains the authority; this only stops the mistake
       being made in the first place.
       --------------------------------------------------------------- */
    function initNinField() {
        var input = document.querySelector('[data-nin-input]');
        var counter = document.querySelector('[data-nin-counter]');
        if (!input) return;

        function syncCounter() {
            if (counter) {
                counter.textContent = input.value.length + '/11';
            }

            var complete = /^\d{11}$/.test(input.value);

            input.classList.toggle('is-complete', complete);
        }

        function stripNonDigits() {
            var cleaned = input.value.replace(/\D/g, '').slice(0, 11);

            if (cleaned !== input.value) {
                input.value = cleaned;
            }
        }

        input.addEventListener('input', function () {
            stripNonDigits();
            syncCounter();
        });

        // A paste can bypass the input handler in some browsers.
        input.addEventListener('paste', function () {
            window.setTimeout(function () {
                stripNonDigits();
                syncCounter();
            }, 0);
        });

        stripNonDigits();
        syncCounter();
    }

    /* ---------------------------------------------------------------
       Record page: select the photograph to enlarge it

       The enlarged copy is fetched from the same route as the thumbnail,
       because its src is read off the image already on the page rather than
       from a second URL in a data attribute. Photographs have exactly one
       route, that route applies EnroleePolicy::view, and a full-size copy
       requested through it is authorised and audited exactly like the
       thumbnail. A second URL would be a second thing to keep in step, and the
       one place where it could be wrong is a privacy control.

       The overlay is built in the DOM rather than written into the template, so
       the record page is unchanged without JavaScript: the photograph is still
       shown at its normal size and the button simply does nothing.
       --------------------------------------------------------------- */
    function initPhotoLightbox() {
        var triggers = document.querySelectorAll('[data-lightbox]');

        if (!triggers.length) return;

        var overlay = null;
        var enlarged = null;
        var closeButton = null;
        var restoreFocusTo = null;

        function build() {
            overlay = document.createElement('div');
            overlay.className = 'lightbox';
            overlay.hidden = true;
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-label', 'Passport photograph');

            enlarged = document.createElement('img');
            enlarged.className = 'lightbox-image';
            enlarged.alt = '';

            closeButton = document.createElement('button');
            closeButton.type = 'button';
            closeButton.className = 'lightbox-close';
            closeButton.textContent = 'Close';
            closeButton.addEventListener('click', close);

            overlay.appendChild(enlarged);
            overlay.appendChild(closeButton);
            document.body.appendChild(overlay);

            // Only a click on the backdrop itself closes it. One that began on
            // the photograph must not, or every attempt to inspect the picture
            // shuts the overlay.
            overlay.addEventListener('click', function (event) {
                if (event.target === overlay) close();
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !overlay.hidden) close();
            });
        }

        function open(trigger) {
            var thumbnail = trigger.querySelector('img');

            if (!thumbnail) return;

            if (!overlay) build();

            enlarged.src = thumbnail.currentSrc || thumbnail.src;
            enlarged.alt = thumbnail.alt;

            // Remembered so closing puts the keyboard back where it was, rather
            // than at the top of the document.
            restoreFocusTo = document.activeElement;

            overlay.hidden = false;
            document.body.classList.add('has-lightbox');
            closeButton.focus();
        }

        function close() {
            if (!overlay || overlay.hidden) return;

            overlay.hidden = true;
            document.body.classList.remove('has-lightbox');

            // The src is dropped rather than left in place: a hidden full-size
            // copy of a passport photograph should not sit in the page waiting
            // to be found in the DOM.
            enlarged.removeAttribute('src');

            if (restoreFocusTo) restoreFocusTo.focus();
            restoreFocusTo = null;
        }

        triggers.forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                open(trigger);
            });
        });
    }

    /**
     * Keep the exports page current while an export is being built.
     *
     * A reload is scheduled only while a row is pending or processing, and the
     * timer is keyed on the element that asked for it, so a page that is
     * already showing a finished export never reloads under the reader. The
     * 10 seconds is deliberate: the job writes progress once per 500 rows, so a
     * faster poll would mostly re-draw an unchanged number.
     */
    function initExportProgressRefresh() {
        var active = document.querySelector('[data-export-progress]');

        if (!active) return;

        var REFRESH_MS = 10000;
        var url = window.location.href;
        var started = Date.now();

        window.setTimeout(function () {
            // A backgrounded tab is not watching a progress bar, and polling a
            // page nobody is looking at is how a shared host runs out of CPU.
            if (document.visibilityState !== 'visible') {
                return;
            }

            // A page left open for hours should not rebuild itself forever; the
            // status it was watching has long since settled either way.
            if (Date.now() - started > REFRESH_MS * 60) {
                return;
            }

            window.location.replace(url);
        }, REFRESH_MS);
    }

    function init() {
        initNavToggle();
        initSubmitGuards();
        initSubmitConfirmations();
        initLocationCascades();
        initPhotoPicker();
        initNinField();
        initConfirmations();
        initRoleProgramToggle();
        initPhotoLightbox();
        initExportProgressRefresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();