<header class="flex flex-wrap items-center justify-between gap-3">
    <p class="text-sm font-semibold text-slate-700">Welcome back, Admin</p>

    <div class="flex items-center gap-2">
        <button class="rounded-xl bg-white/80 p-2 text-slate-600 transition hover:bg-white" type="button" aria-label="Search">
            <span class="inline-grid h-4 w-4 place-items-center text-sm leading-none"><x-heroicon-o-magnifying-glass class="h-4 w-4" /></span>
        </button>

        <button class="relative rounded-xl bg-white/80 p-2 text-slate-600 transition hover:bg-white" type="button" aria-label="Notifications">
            <span class="inline-grid h-4 w-4 place-items-center text-sm leading-none"><x-heroicon-o-bell class="h-4 w-4" /></span>
            <span class="absolute right-1 top-1 rounded-full bg-slate-500 px-1 text-[9px] font-bold text-white">1</span>
        </button>

        <div class="flex items-center gap-2 rounded-full bg-white/85 px-2 py-1.5 shadow-sm shadow-slate-900/5">
            <span class="grid h-7 w-7 place-items-center rounded-full bg-gradient-to-br from-slate-700 to-slate-500 text-xs font-bold text-white">A</span>
            <span class="pr-2 text-xs font-semibold text-slate-700">Admin User</span>
        </div>
    </div>
</header>