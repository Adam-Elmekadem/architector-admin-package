<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    @vite('resources/css/app.css')
</head>
<body class="h-screen w-screen bg-linear-to-br from-slate-100 via-slate-100 to-slate-200 text-slate-800">
    <div class="h-screen w-screen p-0">
        <div class="grid h-full w-full rounded-3xl border border-white/70 bg-white/45 shadow-2xl shadow-slate-900/10 backdrop-blur-xl lg:grid-cols-[230px_minmax(0,1fr)]">
            <aside class="border-b border-white/70 bg-white/60 p-5 lg:border-b-0 lg:border-r lg:p-6">
                @include('admin.layout.sidebar')
            </aside>

            <section class="min-w-0 h-full overflow-y-auto p-4 md:p-6 lg:p-7">
                @include('admin.layout.topbar')

                <main class="mt-4 min-w-0 pb-8">
                    @yield('content')
                </main>
            </section>
        </div>
    </div>
</body>
</html>