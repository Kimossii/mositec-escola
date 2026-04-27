<h1>Titulo Login</h1>

@guest
    <form method="POST" action="/login">
        @csrf

        <input type="email" name="email" placeholder="Email" />
        <input type="password" name="password" placeholder="Password" />

        <button type="submit">Entrar</button>
    </form>
@endguest

@auth
    <p>Bem-vindo, {{ auth()->user()->name }}</p>

    <form method="POST" action="/logout">
        @csrf
        <button type="submit">Logout</button>
    </form>
@endauth
