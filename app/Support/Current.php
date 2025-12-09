<?php

namespace App\Support;

use App\Models\Account;
use App\Models\User;
use App\Models\AccountUser;
use App\Models\Contact;

/**
 * Current context for multi-tenancy
 * Similar to Chatwoot's Current module
 * Stores thread-local context (account, user, etc.)
 */
class Current
{
    protected static ?Account $account = null;
    protected static ?User $user = null;
    protected static ?AccountUser $accountUser = null;
    protected static ?Contact $contact = null;

    public static function setAccount(?Account $account): void
    {
        self::$account = $account;
    }

    public static function account(): ?Account
    {
        return self::$account;
    }

    public static function setUser(?User $user): void
    {
        self::$user = $user;
    }

    public static function user(): ?User
    {
        return self::$user;
    }

    public static function setAccountUser(?AccountUser $accountUser): void
    {
        self::$accountUser = $accountUser;
    }

    public static function accountUser(): ?AccountUser
    {
        return self::$accountUser;
    }

    public static function setContact(?Contact $contact): void
    {
        self::$contact = $contact;
    }

    public static function contact(): ?Contact
    {
        return self::$contact;
    }

    public static function reset(): void
    {
        self::$account = null;
        self::$user = null;
        self::$accountUser = null;
        self::$contact = null;
    }
}

