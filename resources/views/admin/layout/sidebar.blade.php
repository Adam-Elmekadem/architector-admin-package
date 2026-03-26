<div class="space-y-6">
    <div>
        <p class="text-3xl font-black tracking-tight text-slate-800">CoachPro</p>
        <p class="text-xs text-slate-500">Admin workspace</p>
    </div>

    <nav class="space-y-1.5">
        <a
            href="{{ url('/admin/dashboard') }}"
            class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold transition {{ request()->is('admin/dashboard') ? 'bg-slate-800 text-white shadow-lg shadow-slate-900/30' : 'text-slate-600 hover:bg-white/70 hover:text-slate-800' }}"
        >
            <span class="inline-grid h-5 w-5 place-items-center {{ request()->is('admin/dashboard') ? 'text-white' : 'text-slate-500' }}"><x-heroicon-o-home class="h-5 w-5" /></span>
            Dashboard
        </a>

        <a href="{{ url('/admin/dashboard/courses') }}" class="flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-medium transition {{ request()->is('admin/dashboard/courses') ? 'bg-slate-800 text-white shadow-lg shadow-slate-900/30' : 'text-slate-500 hover:bg-white/70 hover:text-slate-800' }}">
            <span class="inline-grid h-5 w-5 place-items-center {{ request()->is('admin/dashboard/courses') ? 'text-white' : 'text-slate-500' }}"><x-heroicon-o-rectangle-group class="h-5 w-5" /></span>
            Courses
        </a>
        <a href="{{ url('/admin/dashboard/stagiaires') }}" class="flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-medium transition {{ request()->is('admin/dashboard/stagiaires') ? 'bg-slate-800 text-white shadow-lg shadow-slate-900/30' : 'text-slate-500 hover:bg-white/70 hover:text-slate-800' }}">
            <span class="inline-grid h-5 w-5 place-items-center {{ request()->is('admin/dashboard/stagiaires') ? 'text-white' : 'text-slate-500' }}"><x-heroicon-o-rectangle-group class="h-5 w-5" /></span>
            Stagiaires
        </a>
        <a href="{{ url('/admin/dashboard/users') }}" class="flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-medium transition {{ request()->is('admin/dashboard/users') ? 'bg-slate-800 text-white shadow-lg shadow-slate-900/30' : 'text-slate-500 hover:bg-white/70 hover:text-slate-800' }}">
            <span class="inline-grid h-5 w-5 place-items-center {{ request()->is('admin/dashboard/users') ? 'text-white' : 'text-slate-500' }}"><x-heroicon-o-users class="h-5 w-5" /></span>
            Users
        </a>

    </nav>
</div>