<?php

namespace App\Http\Requests\Communication\Message;

use App\Support\HtmlSanitizer;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;

class StoreReplyRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'messages';

    public function authorize(): bool
    {
        // Cualquier user autenticado puede intentar responder; el controller
        // valida que sea recipient del mensaje y que allow_replies = true.
        return $this->user() !== null;
    }

    /**
     * El reply se renderiza con `v-html` en Inbox/Show.vue. Sin sanitización
     * un user malicioso (recipient del thread) puede inyectar XSS persistente
     * que se ejecuta en el navegador del super y otros recipients.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('body')) {
            $this->merge(['body' => HtmlSanitizer::clean($this->input('body'))]);
        }
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => __('messages.reply_body_required'),
            'body.max'      => __('messages.reply_body_max'),
        ];
    }
}
