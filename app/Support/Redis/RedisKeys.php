<?php

namespace App\Support\Redis;

/**
 * Redis Key Constants
 * Similar to Chatwoot's Redis::RedisKeys
 * Centralized key patterns for Redis operations
 */
class RedisKeys
{
    // Message source keys - prevent duplicate message processing
    public const MESSAGE_SOURCE_KEY = 'MESSAGE_SOURCE_KEY::%s';

    // Mutex/Lock keys - prevent concurrent processing
    // Similar to Chatwoot's Redis::RedisKeys
    public const WHATSAPP_MESSAGE_MUTEX = 'WHATSAPP_MESSAGE_LOCK::%s::%s';
    // IG_MESSAGE_CREATE_LOCK format: IG_MESSAGE_CREATE_LOCK::<sender_id>::<ig_account_id>
    public const INSTAGRAM_MESSAGE_MUTEX = 'IG_MESSAGE_CREATE_LOCK::%s::%s';
    public const FACEBOOK_MESSAGE_MUTEX = 'FB_MESSAGE_CREATE_LOCK::%s::%s';
    public const EMAIL_MESSAGE_MUTEX = 'EMAIL_CHANNEL_LOCK::%s';

    // Online status keys
    public const ONLINE_STATUS = 'ONLINE_STATUS::%d';
    public const ONLINE_PRESENCE_CONTACTS = 'ONLINE_PRESENCE::%d::CONTACTS';
    public const ONLINE_PRESENCE_USERS = 'ONLINE_PRESENCE::%d::USERS';

    // Authorization status keys
    public const AUTHORIZATION_ERROR_COUNT = 'AUTHORIZATION_ERROR_COUNT:%s:%d';
    public const REAUTHORIZATION_REQUIRED = 'REAUTHORIZATION_REQUIRED:%s:%d';

    // Round robin assignment keys
    public const ROUND_ROBIN_AGENTS = 'ROUND_ROBIN_AGENTS:%d';

    // Conversation keys
    public const CONVERSATION_MAILER_KEY = 'CONVERSATION::%d';
    public const CONVERSATION_MUTE_KEY = 'CONVERSATION::%d::MUTED';
    public const CONVERSATION_DRAFT_MESSAGE = 'CONVERSATION::%d::DRAFT_MESSAGE';
}

