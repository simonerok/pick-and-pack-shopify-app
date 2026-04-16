<?php
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component
{
    public array $activeUsers = [];
    public array $deactivatedUsers = [];
    public ?int $restoreUserId = null;
    public string $restoreUserName = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->role === 'super_admin', 403);
        $this->loadUsers();
    }

    public function loadUsers(): void
    {
        $this->activeUsers = User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'created_at'])
            ->toArray();

        $this->deactivatedUsers = User::onlyTrashed()
            ->orderByDesc('deleted_at')
            ->get(['id', 'name', 'email', 'role', 'deleted_at'])
            ->toArray();
    }

    public function setRestoreTarget(int $userId): void
    {
        abort_unless(auth()->user()?->role === 'super_admin', 403);

        $user = User::withTrashed()->findOrFail($userId);

        $this->restoreUserId = $user->id;
        $this->restoreUserName = $user->name;
    }

    public function restoreUserConfirmed(): void
    {
        abort_unless(auth()->user()?->role === 'super_admin', 403);

        if ($this->restoreUserId === null) {
            return;
        }

        $user = User::withTrashed()->findOrFail($this->restoreUserId);

        if ($user->trashed()) {
            $user->restore();

            activity('auth')
                ->causedBy(auth()->user())
                ->performedOn($user)
                ->event('account_restored')
                ->withProperties([
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'restore_type' => 'restore',
                ])
                ->log('Super admin restored user account');
        }

        $this->loadUsers();
        $this->restoreUserId = null;
        $this->restoreUserName = '';
        session()->flash('status', 'User restored successfully.');

        $this->redirect(route('coworkers', array_filter([
            'shop' => request()->query('shop'),
            'host' => request()->query('host'),
            'embedded' => request()->query('embedded'),
        ], fn ($value) => is_string($value) && $value !== '')), navigate: true);
    }
}; ?>

<div>
<x-auth-session-status class="mb-4" :status="session('status')" />

<main class="w-full min-w-0 px-4 py-8 text-sm" x-data="{ activeTab: 'active' }">
    <div class="flex justify-center mb-6">
        <x-sbb-logo />
    </div>

    <div class="mb-6">
        <p class="text-slate-500 text-xs mb-6 text-center">{{ __('View and manage your coworkers - restore deactivated accounts.') }}</p>
    </div>

    <div class="flex gap-6 mb-4 border-b border-slate-200">
        <button
            type="button"
            @click="activeTab = 'active'"
            :class="activeTab === 'active'
                ? 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-semibold text-slate-800 border-slate-800'
                : 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-normal text-slate-600 hover:text-slate-800 border-transparent'"
        >
            Active users ({{ count($activeUsers) }})
        </button>

        <button
            type="button"
            @click="activeTab = 'deactivated'"
            :class="activeTab === 'deactivated'
                ? 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-semibold text-slate-800 border-slate-800'
                : 'pb-2.5 text-sm transition-colors -mb-px border-b-2 font-normal text-slate-600 hover:text-slate-800 border-transparent'"
        >
            Deactivated users ({{ count($deactivatedUsers) }})
        </button>
    </div>

    <div class="space-y-8">
        {{-- Active users --}}
        <section x-show="activeTab === 'active'" x-cloak>
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto md:overflow-visible">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50/80">
                                <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Name</th>
                                <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Email</th>
                                <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Role</th>
                                <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Created</th>
                                <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($activeUsers as $user)
                                <tr class="border-b border-slate-100 last:border-b-0 hover:bg-slate-50/50">
                                    <td class="px-4 py-2 text-xs text-slate-800">{{ $user['name'] }}</td>
                                    <td class="px-4 py-2 text-xs text-slate-600">{{ $user['email'] }}</td>
                                    <td class="px-4 py-2 text-xs text-slate-600">{{ $user['role'] }}</td>
                                    <td class="px-4 py-2 text-xs text-slate-600">
                                        {{ \Illuminate\Support\Carbon::parse($user['created_at'])->format('Y-m-d H:i') }}
                                    </td>
                                    <td class="px-4 py-2">
                                        <span class="inline-flex px-2 py-0.5 rounded text-[11px] font-medium bg-emerald-100 text-emerald-800">
                                            Active
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-4 py-6 text-center text-slate-500 text-xs">
                                        No active users.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- Deactivated users --}}
        <section x-show="activeTab === 'deactivated'" x-cloak>

            <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="overflow-x-auto md:overflow-visible">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-200 bg-slate-50/80">
                                <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Name</th>
                                <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Email</th>
                                <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Role</th>
                                <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Deactivated at</th>
                                <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Status</th>
                                <th class="px-4 py-2 text-[11px] font-medium uppercase tracking-wider text-slate-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($deactivatedUsers as $user)
                                <tr class="border-b border-slate-100 last:border-b-0 hover:bg-slate-50/50">
                                    <td class="px-4 py-2 text-xs text-slate-800">{{ $user['name'] }}</td>
                                    <td class="px-4 py-2 text-xs text-slate-600">{{ $user['email'] }}</td>
                                    <td class="px-4 py-2 text-xs text-slate-600">{{ $user['role'] }}</td>
                                    <td class="px-4 py-2 text-xs text-slate-600">
                                        {{ \Illuminate\Support\Carbon::parse($user['deleted_at'])->format('Y-m-d H:i') }}
                                    </td>
                                    <td class="px-4 py-2">
                                        <span class="inline-flex px-2 py-0.5 rounded text-[11px] font-medium bg-red-100 text-red-800">
                                            Deactivated
                                        </span>
                                    </td>
                                    <td class="px-4 py-2">
                                        <button
                                            type="button"
                                            wire:click="setRestoreTarget({{ $user['id'] }})"
                                            x-on:click="$dispatch('open-modal', 'confirm-user-restore')"
                                            class="inline-flex items-center justify-center gap-1 px-3 py-1.5 rounded-md border border-black bg-transparent text-black text-xs font-medium hover:bg-black hover:text-white transition-colors"
                                        >
                                            Restore
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-4 py-6 text-center text-slate-500 text-xs">
                                        No deactivated users.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</main>

<x-modal name="confirm-user-restore" focusable maxWidth="md">
    <form wire:submit="restoreUserConfirmed" class="p-6">
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Restore user?') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __('Are you sure you want to restore this user account?') }}
            <span class="font-semibold text-slate-800" x-text="$wire.restoreUserName"></span>
        </p>

        <div class="mt-6 flex justify-end">
            <x-secondary-button
                x-on:click="$dispatch('close')"
                wire:click="$set('restoreUserId', null); $set('restoreUserName', '')"
            >
                {{ __('No') }}
            </x-secondary-button>

            <x-primary-button class="ms-3">
                {{ __('Yes, restore') }}
            </x-primary-button>
        </div>
    </form>
</x-modal>
</div>