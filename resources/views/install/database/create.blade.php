<x-layouts.install>
    <x-slot name="title">
        {{ trans('install.steps.database') }}
    </x-slot>

    <x-slot name="content">
        <div class="grid sm:grid-cols-6 gap-x-8 gap-y-6 my-3.5">
            <x-form.group.text name="hostname" label="{{ trans('install.database.hostname') }}" value="{{ old('hostname', 'none') }}" not-required form-group-class="sm:col-span-6" />
    
            <x-form.group.text name="username" label="{{ trans('install.database.username') }}" value="{{ old('username', 'none') }}" not-required form-group-class="sm:col-span-6" />

            <x-form.group.password name="password" label="{{ trans('install.database.password') }}" value="{{'none'}}" not-required form-group-class="sm:col-span-6" />
    
            <x-form.group.text name="database" label="{{ trans('install.database.name') }}" value="{{ old('database', 'none') }}" not-required form-group-class="sm:col-span-6" />
        </div>
    </x-slot>
</x-layouts.install>
