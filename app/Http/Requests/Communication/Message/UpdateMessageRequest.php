<?php

namespace App\Http\Requests\Communication\Message;

use App\Models\Message;
use App\Support\HtmlSanitizer;
use App\Http\Requests\Concerns\DerivesAttributesFromLang;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMessageRequest extends FormRequest
{
    use DerivesAttributesFromLang;

    protected $attributeNamespace = 'messages';

    public function authorize(): bool
    {
        return (bool) $this->user()?->hasRole('super');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('body')) {
            $this->merge(['body' => HtmlSanitizer::clean($this->input('body'))]);
        }
        if ($this->has('subject')) {
            $this->merge(['subject' => strip_tags((string) $this->input('subject'))]);
        }
    }

    public function rules(): array
    {
        return [
            'subject'       => ['required', 'string', 'max:200'],
            'body'          => ['required', 'string'],
            'audience_type' => ['required', Rule::in([
                Message::AUDIENCE_GLOBAL,
                Message::AUDIENCE_TENANT,
                Message::AUDIENCE_USER,
            ])],
            'audience_id'   => ['nullable', 'integer', 'required_unless:audience_type,' . Message::AUDIENCE_GLOBAL],
            'allow_replies' => ['nullable', 'boolean'],
            'is_active'     => ['nullable', 'boolean'],
            'expires_at'    => ['nullable', 'date'],
            'publish_now'   => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'subject.required'       => __('messages.subject_required'),
            'body.required'          => __('messages.body_required'),
            'audience_type.required' => __('messages.audience_type_required'),
            'audience_id.required_unless' => __('messages.audience_id_required'),
        ];
    }
}
