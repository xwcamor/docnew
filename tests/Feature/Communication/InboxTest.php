<?php

namespace Tests\Feature\Communication;

use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\MessageReply;
use App\Services\Communication\MessageService;

class InboxTest extends MessageTestCase
{
    /**
     * El endpoint de polling del bell devuelve el payload JSON del inbox y NO
     * queda tapado por la ruta inbox/{slug} (orden importa).
     */
    public function test_inbox_poll_endpoint_returns_payload(): void
    {
        $user = $this->makeUser(1, 'user', 'poll');
        $this->actingAs($user);

        $this->get(route('communication.inbox.poll'))
            ->assertOk()
            ->assertJsonStructure(['recent', 'unread', 'processing', 'unread_messages', 'messages']);
    }

    public function test_user_sees_only_their_messages(): void
    {
        $super = $this->actingAsSuper();
        $userA = $this->makeUser(1, 'user', 'a');
        $userB = $this->makeUser(2, 'user', 'b');

        // Mensaje para userA
        $msgA = Message::create([
            'subject' => 'For A', 'body' => '<p>a</p>',
            'created_by' => $super->id,
            'audience_type' => Message::AUDIENCE_USER,
            'audience_id'   => $userA->id,
            'is_active' => true, 'allow_replies' => false,
        ]);
        // Mensaje para userB
        $msgB = Message::create([
            'subject' => 'For B', 'body' => '<p>b</p>',
            'created_by' => $super->id,
            'audience_type' => Message::AUDIENCE_USER,
            'audience_id'   => $userB->id,
            'is_active' => true, 'allow_replies' => false,
        ]);

        $service = app(MessageService::class);
        $service->publish($msgA);
        $service->publish($msgB);

        $this->actingAs($userA);
        $resp = $this->get(route('communication.inbox.index'));
        $resp->assertOk();

        // userA solo ve mensaje For A.
        $inbox = $service->inboxFor($userA)->get();
        $this->assertCount(1, $inbox);
        $this->assertSame('For A', $inbox->first()->subject);
    }

    public function test_show_marks_as_read(): void
    {
        $super = $this->actingAsSuper();
        $user  = $this->makeUser(1, 'user', 'r');

        $msg = Message::create([
            'subject' => 'Read me', 'body' => '<p>x</p>',
            'created_by' => $super->id,
            'audience_type' => Message::AUDIENCE_USER,
            'audience_id' => $user->id,
            'is_active' => true, 'allow_replies' => false,
        ]);
        app(MessageService::class)->publish($msg);

        $this->actingAs($user);
        $resp = $this->get(route('communication.inbox.show', $msg->slug));
        $resp->assertOk();

        $recipient = MessageRecipient::where('message_id', $msg->id)
            ->where('user_id', $user->id)
            ->first();
        $this->assertNotNull($recipient->read_at);
    }

    public function test_unread_count_decreases_after_read(): void
    {
        $super = $this->actingAsSuper();
        $user  = $this->makeUser(1, 'user', 'u');

        $msg = Message::create([
            'subject' => 'Count', 'body' => '<p>x</p>',
            'created_by' => $super->id,
            'audience_type' => Message::AUDIENCE_USER,
            'audience_id' => $user->id,
            'is_active' => true, 'allow_replies' => false,
        ]);
        $service = app(MessageService::class);
        $service->publish($msg);

        $this->assertSame(1, $service->unreadCountForUser($user));

        $service->markAsRead($user, $msg);
        $this->assertSame(0, $service->unreadCountForUser($user));
    }

    public function test_reply_when_allowed(): void
    {
        $super = $this->actingAsSuper();
        $user  = $this->makeUser(1, 'user', 'rep');

        $msg = Message::create([
            'subject' => 'Debate', 'body' => '<p>?</p>',
            'created_by' => $super->id,
            'audience_type' => Message::AUDIENCE_USER,
            'audience_id' => $user->id,
            'is_active' => true, 'allow_replies' => true,
        ]);
        app(MessageService::class)->publish($msg);

        $this->actingAs($user);
        // Visitar show primero para marcar como leido (no afecta el reply test).
        $this->get(route('communication.inbox.show', $msg->slug));

        $resp = $this->post(route('communication.inbox.reply', $msg->slug), [
            'body' => 'Mi respuesta',
        ]);
        $resp->assertRedirect();
        $this->assertDatabaseHas('message_replies', [
            'message_id' => $msg->id,
            'user_id'    => $user->id,
            'body'       => 'Mi respuesta',
        ]);
    }

    public function test_reply_blocked_when_not_allowed(): void
    {
        $super = $this->actingAsSuper();
        $user  = $this->makeUser(1, 'user', 'nor');

        $msg = Message::create([
            'subject' => 'Sin debate', 'body' => '<p>x</p>',
            'created_by' => $super->id,
            'audience_type' => Message::AUDIENCE_USER,
            'audience_id' => $user->id,
            'is_active' => true, 'allow_replies' => false,
        ]);
        app(MessageService::class)->publish($msg);

        $this->actingAs($user);
        $resp = $this->post(route('communication.inbox.reply', $msg->slug), [
            'body' => 'Intento responder',
        ]);
        // 403 se convierte en redirect + flash error en este proyecto.
        $resp->assertRedirect();
        $resp->assertSessionHas('error');
        $this->assertSame(0, MessageReply::count());
    }

    public function test_reply_blocked_when_not_recipient(): void
    {
        $super = $this->actingAsSuper();
        $target  = $this->makeUser(1, 'user', 'target');
        $intruder = $this->makeUser(2, 'user', 'intruder');

        $msg = Message::create([
            'subject' => 'Private', 'body' => '<p>x</p>',
            'created_by' => $super->id,
            'audience_type' => Message::AUDIENCE_USER,
            'audience_id' => $target->id,
            'is_active' => true, 'allow_replies' => true,
        ]);
        app(MessageService::class)->publish($msg);

        $this->actingAs($intruder);
        $resp = $this->post(route('communication.inbox.reply', $msg->slug), [
            'body' => 'No me corresponde',
        ]);
        $resp->assertRedirect();
        $resp->assertSessionHas('error');
    }

    public function test_show_blocked_when_not_recipient(): void
    {
        $super = $this->actingAsSuper();
        $target = $this->makeUser(1, 'user', 'tgt');
        $other  = $this->makeUser(1, 'user', 'oth');

        $msg = Message::create([
            'subject' => 'P', 'body' => '<p>x</p>',
            'created_by' => $super->id,
            'audience_type' => Message::AUDIENCE_USER,
            'audience_id' => $target->id,
            'is_active' => true, 'allow_replies' => false,
        ]);
        app(MessageService::class)->publish($msg);

        $this->actingAs($other);
        $resp = $this->get(route('communication.inbox.show', $msg->slug));
        $resp->assertRedirect();
        $resp->assertSessionHas('error');
    }

    public function test_mark_all_read(): void
    {
        $super = $this->actingAsSuper();
        $user = $this->makeUser(1, 'user', 'mall');

        for ($i = 0; $i < 3; $i++) {
            $msg = Message::create([
                'subject' => "M{$i}", 'body' => '<p>x</p>',
                'created_by' => $super->id,
                'audience_type' => Message::AUDIENCE_USER,
                'audience_id' => $user->id,
                'is_active' => true, 'allow_replies' => false,
            ]);
            app(MessageService::class)->publish($msg);
        }

        $this->actingAs($user);
        $this->assertSame(3, app(MessageService::class)->unreadCountForUser($user));

        $resp = $this->post(route('communication.inbox.mark_all_read'));
        $resp->assertRedirect();
        $this->assertSame(0, app(MessageService::class)->unreadCountForUser($user));
    }

    public function test_expired_message_excluded_from_unread_count(): void
    {
        $super = $this->actingAsSuper();
        $user = $this->makeUser(1, 'user', 'exp');

        $msg = Message::create([
            'subject' => 'Expired', 'body' => '<p>x</p>',
            'created_by' => $super->id,
            'audience_type' => Message::AUDIENCE_USER,
            'audience_id' => $user->id,
            'is_active' => true, 'allow_replies' => false,
            'expires_at' => now()->subHour(),
        ]);
        app(MessageService::class)->publish($msg);

        $this->assertSame(0, app(MessageService::class)->unreadCountForUser($user));
    }

    public function test_inactive_message_excluded(): void
    {
        $super = $this->actingAsSuper();
        $user = $this->makeUser(1, 'user', 'ina');

        $msg = Message::create([
            'subject' => 'Inactive', 'body' => '<p>x</p>',
            'created_by' => $super->id,
            'audience_type' => Message::AUDIENCE_USER,
            'audience_id' => $user->id,
            'is_active' => false, 'allow_replies' => false,
        ]);
        app(MessageService::class)->publish($msg);

        $this->assertSame(0, app(MessageService::class)->unreadCountForUser($user));
    }
}
