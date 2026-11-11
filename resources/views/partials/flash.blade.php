@if (session('success') || session('status'))
    <x-ui.alert variant="success" class="mb-4">
        {{ session('success') ?? session('status') }}
    </x-ui.alert>
@endif

@if (session('warning'))
    <x-ui.alert variant="warning" class="mb-4">
        {{ session('warning') }}
    </x-ui.alert>
@endif

@if (session('error'))
    <x-ui.alert variant="danger" class="mb-4">
        {{ session('error') }}
    </x-ui.alert>
@endif

@if ($errors->any())
    <x-ui.alert variant="danger" title="Please check the highlighted fields" class="mb-4">
        <ul class="mt-1 list-disc list-inside space-y-0.5 text-xs">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-ui.alert>
@endif

