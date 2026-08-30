<?php

namespace App\Http\Inertia;

use Illuminate\Contracts\Support\Arrayable;
use Inertia\ResponseFactory;
use Inertia\Support\ProvidesInertiaProperties;

/** Подменяет штатный Inertia-Response на SanitizedInertiaResponse (см. его док). */
class SanitizingInertiaFactory extends ResponseFactory
{
    public function render(string $component, $props = []): SanitizedInertiaResponse
    {
        if (config('inertia.ensure_pages_exist', false)) {
            $this->findComponentOrFail($component);
        }

        if ($props instanceof Arrayable) {
            $props = $props->toArray();
        } elseif ($props instanceof ProvidesInertiaProperties) {
            $props = [$props];
        }

        return new SanitizedInertiaResponse(
            $component,
            array_merge($this->sharedProps, $props),
            $this->rootView,
            $this->getVersion(),
            $this->encryptHistory ?? config('inertia.history.encrypt', false),
            $this->urlResolver,
        );
    }
}
