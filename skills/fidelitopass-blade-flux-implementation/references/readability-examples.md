# Readable Blade calibration

Read only to compare text and paragraph structure. Translation keys and action names are illustrative; use existing application contracts. This is a field fragment, not a page or form template.

```blade
<flux:field>
    <flux:label>
        {{ __('promotion.reward_title') }}
    </flux:label>
    <flux:input name="rewardTitle" type="text" wire:model="rewardTitle" required />
    <flux:error name="rewardTitle" />
</flux:field>

<flux:field>
    <flux:label>
        {{ __('promotion.reward_description') }}
    </flux:label>
    <flux:textarea name="rewardDescription" wire:model="rewardDescription" />
    <flux:error name="rewardDescription" />
</flux:field>
```

A field's related elements remain adjacent; a blank separates complete fields. Body text has its own line. The input's native required state follows the binding and semantic attribute groups. Neither a comment for each field nor a global one-line opening-tag rule makes this more readable. Verify actual Free component availability, label association, error rendering and localization during integration.

For a complex opening tag, wrap attributes by the established semantic groups. Preserve operation-sensitive attribute bags and inline whitespace; the formatting example does not authorize changing rendered behavior.
