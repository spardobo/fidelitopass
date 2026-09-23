<x-layouts::app title="Panel del negocio">
    <main class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6 sm:px-6">
        <header class="space-y-2">
            <flux:heading size="xl" level="1">Tu negocio</flux:heading>
            <flux:text>Administrá los datos de tu negocio desde este panel.</flux:text>
        </header>

        <section aria-label="Perfil del negocio" class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <flux:heading level="2">{{ $business->name }}</flux:heading>
            <flux:text>Zona horaria: {{ $business->timezone }}</flux:text>
            <div class="mt-5">
                <flux:button :href="route('business.edit')" wire:navigate>Editar perfil del negocio</flux:button>
            </div>
        </section>
    </main>
</x-layouts::app>
