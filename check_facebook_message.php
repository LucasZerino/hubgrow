<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== VERIFICAÇÃO DA JORNADA DA MENSAGEM FACEBOOK ===\n\n";

// Busca última mensagem do Facebook
$lastMessage = \App\Models\Message::whereHas('conversation.inbox.channel', function($q) {
    $q->where('channel_type', \App\Models\Channel\FacebookChannel::class);
})->latest()->first();

if (!$lastMessage) {
    echo "❌ Nenhuma mensagem do Facebook encontrada no banco de dados.\n";
    echo "\nIsso pode significar:\n";
    echo "1. A mensagem ainda não foi processada\n";
    echo "2. Ocorreu um erro no processamento\n";
    echo "3. A mensagem foi recebida mas não tinha conteúdo de texto\n\n";
    
    // Verifica se há contatos do Facebook
    $contacts = \App\Models\ContactInbox::whereHas('inbox.channel', function($q) {
        $q->where('channel_type', \App\Models\Channel\FacebookChannel::class);
    })->get();
    
    if ($contacts->count() > 0) {
        echo "✅ Encontrados {$contacts->count()} contato(s) do Facebook:\n";
        foreach ($contacts as $contactInbox) {
            echo "  - Contato ID: {$contactInbox->contact_id}, Source ID: {$contactInbox->source_id}, Nome: {$contactInbox->contact->name}\n";
        }
    } else {
        echo "❌ Nenhum contato do Facebook encontrado.\n";
    }
    
    // Verifica se há conversas do Facebook
    $conversations = \App\Models\Conversation::whereHas('inbox.channel', function($q) {
        $q->where('channel_type', \App\Models\Channel\FacebookChannel::class);
    })->get();
    
    if ($conversations->count() > 0) {
        echo "\n✅ Encontradas {$conversations->count()} conversa(s) do Facebook:\n";
        foreach ($conversations as $conversation) {
            echo "  - Conversa ID: {$conversation->id}, Contato ID: {$conversation->contact_id}, Status: {$conversation->status}\n";
        }
    } else {
        echo "\n❌ Nenhuma conversa do Facebook encontrada.\n";
    }
    
    exit(1);
}

echo "✅ MENSAGEM ENCONTRADA:\n";
echo "  - Mensagem ID: {$lastMessage->id}\n";
echo "  - Conteúdo: " . ($lastMessage->content ?: '(vazio)') . "\n";
echo "  - Tipo: " . ($lastMessage->message_type === 0 ? 'Incoming' : 'Outgoing') . "\n";
echo "  - Source ID: {$lastMessage->source_id}\n";
echo "  - Criada em: {$lastMessage->created_at}\n\n";

// Verifica conversa
$conversation = $lastMessage->conversation;
if ($conversation) {
    echo "✅ CONVERSA:\n";
    echo "  - Conversa ID: {$conversation->id}\n";
    echo "  - Display ID: {$conversation->display_id}\n";
    echo "  - Status: {$conversation->status}\n";
    echo "  - Criada em: {$conversation->created_at}\n\n";
    
    // Verifica contato
    $contact = $conversation->contact;
    if ($contact) {
        echo "✅ CONTATO:\n";
        echo "  - Contato ID: {$contact->id}\n";
        echo "  - Nome: {$contact->name}\n";
        echo "  - Identifier: {$contact->identifier}\n";
        echo "  - Criado em: {$contact->created_at}\n\n";
        
        // Verifica ContactInbox
        $contactInbox = \App\Models\ContactInbox::where('contact_id', $contact->id)
            ->where('inbox_id', $conversation->inbox_id)
            ->first();
        
        if ($contactInbox) {
            echo "✅ CONTACT INBOX:\n";
            echo "  - ContactInbox ID: {$contactInbox->id}\n";
            echo "  - Source ID: {$contactInbox->source_id}\n";
            echo "  - HMAC Verified: " . ($contactInbox->hmac_verified ? 'Sim' : 'Não') . "\n\n";
        } else {
            echo "❌ ContactInbox não encontrado!\n\n";
        }
    } else {
        echo "❌ Contato não encontrado na conversa!\n\n";
    }
} else {
    echo "❌ Conversa não encontrada para a mensagem!\n\n";
}

// Verifica anexos
$attachments = $lastMessage->attachments;
if ($attachments && $attachments->count() > 0) {
    echo "✅ ANEXOS ({$attachments->count()}):\n";
    foreach ($attachments as $attachment) {
        echo "  - Tipo: {$attachment->file_type}, URL Externa: {$attachment->external_url}\n";
    }
} else {
    echo "ℹ️  Nenhum anexo encontrado.\n";
}

echo "\n=== FIM DA VERIFICAÇÃO ===\n";

