<?php

namespace App\Policies;

use App\Models\Enrolee;
use App\Models\User;

/**
 * Single-record authorisation for enrolees.
 *
 * The rules, from the brief:
 *
 *   Admin        - full access to every record in every program. Editing a
 *                  submitted record is permitted but always audited.
 *   Program User - create anything in their own program; view and edit only
 *                  what they created, and only while it is a draft. Submitted
 *                  records are locked: 403 on edit.
 *   Reviewer     - read-only over every record in their one program, drafts
 *                  and submitted alike. Never edit, delete or export.
 *
 * Collection-level visibility is enforced separately by
 * Enrolee::scopeVisibleTo(), which every list, count and export must use.
 */
class EnroleePolicy
{
    /**
     * Whether the list screen is offered at all.
     *
     * Every role may browse. What the list contains is decided by
     * Enrolee::scopeVisibleTo() — a Program User sees their own records, a
     * Reviewer their program's, an administrator everything — so this answers
     * only whether to show the link, and has no rows to protect.
     *
     * Declared because the other policies declare viewAny and the views ask for
     * it. A policy method that does not exist is not "allowed" and not "denied"
     * by the Gate, it is undefined, and an undefined ability denies: a view
     * asking @can('viewAny', Enrolee::class) would quietly render no link at all
     * and nothing would say why.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Viewing a single record, including its photograph. */
    public function view(User $user, Enrolee $enrolee): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isReviewer()) {
            return $enrolee->isInUsersProgram($user);
        }

        // A Program User needs both: authorship and membership of their own
        // program. Requiring only one of the two would let a record whose
        // creator and program disagree become visible by guessing its id.
        return $enrolee->isOwnedBy($user) && $enrolee->isInUsersProgram($user);
    }

    /** Creating a new record. Reviewers cannot. */
    public function create(User $user): bool
    {
        return ! $user->isReviewer();
    }

    /** Editing: admins always; program users only their own drafts. */
    public function update(User $user, Enrolee $enrolee): bool
    {
        return $enrolee->isEditableBy($user);
    }

    /**
     * Deleting: admin for anything; a program user only for their own draft.
     */
    public function delete(User $user, Enrolee $enrolee): bool
    {
        return $enrolee->isDeletableBy($user);
    }

    /**
     * Submitting is the same gate as editing, but spelled out so the intent is
     * legible at the call site: only an editable record can be submitted.
     */
    public function submit(User $user, Enrolee $enrolee): bool
    {
        return $this->update($user, $enrolee);
    }

    /** Exporting is an administrator capability only. */
    public function export(User $user): bool
    {
        return $user->isAdmin();
    }

    /** Reviewer list access. */
    public function review(User $user): bool
    {
        return $user->isReviewer();
    }
}