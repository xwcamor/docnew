<?php

namespace Tests\Feature\Communication;

use App\Models\Message;
use App\Models\MessageRecipient;
use App\Models\User;
use App\Services\Communication\MessageService;

class MessageCrudTest extends MessageTestCase
{
    public function test_super_can_access_messages_index(): void
    {
        $this->actingAsSuper();
        $resp = $this->get(route('communication.messages.index'));
        $resp->assertOk();
    }

    public function test_admin_cannot_access_messages_index(): void
    {
        // El handler convierte 403 a redirect + flash error (ver bootstrap/app.php).
        $this->actingAsAdmin();
        $resp = $this->get(route('communication.messages.index'));
        $resp->assertRedirect();
        $resp->assertSessionHas('error');
    }

    public function test_super_can_create_global_message(): void
    {
        $super = $this->actingAsSuper();
        $resp = $this->post(route('communication.messages.store'), [
            'subject'       => 'Aviso general',
            'body'          => '<p>Hola a todos</p>',
            'audience_type' => 'global',
            'allow_replies' => false,
            'is_active'     => true,
            'publish_now'   => false,
        ]);
        $resp->assertRedirect();
        $this->assertDatabaseHas('messages', [
            'subject'       => 'Aviso general',
            'audience_type' => 'global',
            'audience_id'   => null,
            'created_by'    => $super->id,
        ]);
    }

    public function test_super_can_create_tenant_message(): void
    {
        $this->actingAsSuper();
        $resp = $this->post(route('communication.messages.store'), [
            'subject'       => 'Aviso T1',
            'body'          => '<p>Para Tenant 1</p>',
            'audience_type' => 'tenant',
            'audience_id'   => 1,
            'allow_replies' => false,
            'is_active'     => true,
        ]);
        $resp->assertRedirect();
        $this->assertDatabaseHas('messages', [
            'subject'       => 'Aviso T1',
            'audience_type' => 'tenant',
            'audience_id'   => 1,
        ]);
    }

    public function test_super_can_create_user_message(): void
    {
        $this->actingAsSuper();
        $target = $this->makeUser(1, 'user', 'target');

        $resp = $this->post(route('communication.messages.store'), [
            'subject'       => 'Mensaje 1:1',
            'body'          => '<p>Solo para ti</p>',
            'audience_type' => 'user',
            'audience_id'   => $target->id,
            'allow_replies' => true,
            'is_active'     => true,
        ]);
        $resp->assertRedirect();
        $this->assertDatabaseHas('messages', [
            'subject'       => 'Mensaje 1:1',
            'audience_type' => 'user',
            'audience_id'   => $target->id,
            'allow_replies' => true,
        ]);
    }

    public function test_admin_cannot_create_message(): void
    {
        $this->actingAsAdmin();
        $resp = $this->post(route('communication.messages.store'), [
            'subject'       => 'X',
            'body'          => '<p>X</p>',
            'audience_type' => 'global',
        ]);
        $resp->assertRedirect();
        $this->assertSame(0, Message::count());
    }

    public function test_store_rejects_missing_fields(): void
    {
        $this->actingAsSuper();
        $resp = $this->post(route('communication.messages.store'), []);
        $resp->assertSessionHasErrors(['subject', 'body', 'audience_type']);
    }

    public function test_store_rejects_tenant_without_audience_id(): void
    {
        $this->actingAsSuper();
        $resp = $this->post(route('communication.messages.store'), [
            'subject'       => 'Sin id',
            'body'          => '<p>x</p>',
            'audience_type' => 'tenant',
        ]);
        $resp->assertSessionHasErrors('audience_id');
    }

    public function test_publish_creates_recipients_for_global(): void
    {
        $super = $this->actingAsSuper();
        // Crear 2 users humanos en cada tenant + 1 system user (rol api).
        $this->makeUser(1, 'user', 'p1');
        $this->makeUser(1, 'user', 'p2');
        $this->makeUser(2, 'user', 'p3');
        $apiUser = $this->makeUser(1, 'api', 'apiuser');

        $msg = Message::create([
            'subject'       => 'Global',
            'body'          => '<p>x</p>',
            'created_by'    => $super->id,
            'audience_type' => Message::AUDIENCE_GLOBAL,
            'audience_id'   => null,
            'is_active'     => true,
            'allow_replies' => false,
        ]);

        $service = app(MessageService::class);
        $count = $service->publish($msg);

        // El super tambien recibe (es un user humano sin rol api).
        // Total esperado: super + p1 + p2 + p3 = 4. Excluido apiUser.
        $this->assertSame(4, $count);
        $this->assertSame(4, MessageRecipient::where('message_id', $msg->id)->count());
        $this->assertFalse(
            MessageRecipient::where('message_id', $msg->id)
                ->where('user_id', $apiUser->id)
                ->exists()
        );
    }

    public function test_publish_creates_recipients_for_tenant(): void
    {
        $super = $this->actingAsSuper();
        $this->makeUser(1, 'user', 't1a');
        $this->makeUser(1, 'user', 't1b');
        $this->makeUser(2, 'user', 't2a');

        $msg = Message::create([
            'subject'       => 'T1',
            'body'          => '<p>x</p>',
            'created_by'    => $super->id,
            'audience_type' => Message::AUDIENCE_TENANT,
            'audience_id'   => 1,
            'is_active'     => true,
            'allow_replies' => false,
        ]);
        $count = app(MessageService::class)->publish($msg);
        $this->assertSame(2, $count);
    }

    public function test_publish_creates_recipients_for_user(): void
    {
        $super = $this->actingAsSuper();
        $target = $this->makeUser(1, 'user', 'one');
        $other  = $this->makeUser(1, 'user', 'two');

        $msg = Message::create([
            'subject'       => '1:1',
            'body'          => '<p>x</p>',
            'created_by'    => $super->id,
            'audience_type' => Message::AUDIENCE_USER,
            'audience_id'   => $target->id,
            'is_active'     => true,
            'allow_replies' => false,
        ]);
        $count = app(MessageService::class)->publish($msg);

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('message_recipients', [
            'message_id' => $msg->id,
            'user_id'    => $target->id,
        ]);
        $this->assertDatabaseMissing('message_recipients', [
            'message_id' => $msg->id,
            'user_id'    => $other->id,
        ]);
    }

    public function test_super_can_soft_delete_message(): void
    {
        $super = $this->actingAsSuper();
        $msg = Message::create([
            'subject' => 'Borrame', 'body' => '<p>x</p>',
            'created_by' => $super->id,
            'audience_type' => Message::AUDIENCE_GLOBAL,
            'is_active' => true, 'allow_replies' => false,
        ]);

        $resp = $this->delete(route('communication.messages.deleteSave', $msg->slug), [
            'confirm_subject'     => 'Borrame',
            'deleted_description' => 'Ya no aplica',
        ]);
        $resp->assertRedirect();
        $this->assertSoftDeleted('messages', ['id' => $msg->id]);
    }

    public function test_delete_only_needs_reason(): void
    {
        // Borrado estándar: ya NO se exige escribir el asunto, solo el motivo.
        $super = $this->actingAsSuper();
        $msg = Message::create([
            'subject' => 'Real', 'body' => '<p>x</p>',
            'created_by' => $super->id,
            'audience_type' => Message::AUDIENCE_GLOBAL,
            'is_active' => true, 'allow_replies' => false,
        ]);

        $resp = $this->delete(route('communication.messages.deleteSave', $msg->slug), [
            'deleted_description' => 'ya no aplica',
        ]);
        $resp->assertSessionHasNoErrors();
        $this->assertSoftDeleted('messages', ['id' => $msg->id]);
    }

    public function test_publish_is_idempotent(): void
    {
        $super = $this->actingAsSuper();
        $this->makeUser(1, 'user', 'i1');
        $this->makeUser(1, 'user', 'i2');

        $msg = Message::create([
            'subject'       => 'Iter',
            'body'          => '<p>x</p>',
            'created_by'    => $super->id,
            'audience_type' => Message::AUDIENCE_TENANT,
            'audience_id'   => 1,
            'is_active'     => true,
            'allow_replies' => false,
        ]);
        app(MessageService::class)->publish($msg);
        $count2 = app(MessageService::class)->publish($msg);
        $this->assertSame(0, $count2); // segunda llamada no duplica
        $this->assertSame(2, MessageRecipient::where('message_id', $msg->id)->count());
    }
}
