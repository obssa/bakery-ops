<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /stations/{station}: any subset of the names (both languages, rule 9), requires_overseer, tracks_shifts and sort. The code is fixed
 * once a station exists (routing, permissions and the seeders name stations by it), so sending one is refused rather than
 * ignored.
 */
final class UpdateStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'code' => ['prohibited'],
            'name_en' => ['sometimes', 'required', 'string', 'max:120'],
            'name_am' => ['sometimes', 'required', 'string', 'max:120'],
            'requires_overseer' => ['sometimes', 'required', 'boolean'],
            'tracks_shifts' => ['sometimes', 'required', 'boolean'],
            'sort' => ['sometimes', 'required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, mixed> only the attributes present in the request
     */
    public function changes(): array
    {
        $data = $this->validated();
        $out = [];
        foreach (['name_en', 'name_am'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = (string) $data[$key];
            }
        }
        if (array_key_exists('requires_overseer', $data)) {
            $out['requires_overseer'] = (bool) $data['requires_overseer'];
        }
        if (array_key_exists('tracks_shifts', $data)) {
            $out['tracks_shifts'] = (bool) $data['tracks_shifts'];
        }
        if (array_key_exists('sort', $data)) {
            $out['sort'] = (int) $data['sort'];
        }

        return $out;
    }
}
