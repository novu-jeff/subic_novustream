<?php

/**
 * Check if a stored password hash looks like Bcrypt (avoids RuntimeException when stored value is plain or other algo).
 */
function isBcryptHash(?string $hash): bool
{
    return $hash !== null
        && strlen($hash) === 60
        && preg_match('/^\$2[ay]\$\d{2}\$/', $hash);
}

/**
 * Verify password against stored value (bcrypt or legacy plain), optionally re-hashing to bcrypt.
 */
function passwordVerifyAndUpgrade(string $plain, ?string $stored, $model = null): bool
{
    if ($stored === null || $stored === '') {
        return false;
    }
    if (isBcryptHash($stored)) {
        return \Illuminate\Support\Facades\Hash::check($plain, $stored);
    }
    // Legacy: plain text or other – verify then optionally upgrade to bcrypt
    $valid = hash_equals($stored, $plain);
    if ($valid && $model !== null) {
        $model->password = \Illuminate\Support\Facades\Hash::make($plain);
        $model->saveQuietly();
    }
    return $valid;
}

function implodeWithAnd(array $items) {
    $count = count($items);
    if ($count === 0) return '';
    if ($count === 1) return ucfirst($items[0]);
    if ($count === 2) return ucfirst(implode(' and ', $items));
    $last = array_pop($items);
    return ucfirst(implode(', ', $items)) . ' and ' . $last;
}

