<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="FidelitoPass ayuda a tu negocio a crear retos de visitas, sumar puntos y recompensar a tus clientes con una tarjeta en Google Wallet.">
    <title>FidelitoPass — Haz que volver sea parte del juego</title>
    <script src="{{ asset('landing.js') }}"></script>
    <link rel="stylesheet" href="{{ asset('landing.css') }}">
</head>
<body>
    <a class="skip-link" href="#contenido">Ir al contenido</a>
    <header class="site-header">
        <div class="container header-inner">
            <a class="wordmark" href="#inicio" aria-label="FidelitoPass, volver al inicio">Fidelito<span>Pass</span></a>
            <nav class="site-nav" aria-label="Secciones de la página">
                <a href="#como-funciona">Cómo funciona</a>
                <a href="#retos">Retos</a>
                <a href="#negocios">Para negocios</a>
            </nav>
            <details class="mobile-menu">
                <summary>Menú</summary>
                <nav aria-label="Secciones de la página">
                    <a href="#como-funciona">Cómo funciona</a>
                    <a href="#retos">Retos</a>
                    <a href="#negocios">Para negocios</a>
                </nav>
            </details>
            <button class="theme-toggle" type="button" aria-label="Activar modo oscuro" aria-pressed="false">Modo oscuro</button>
            <a class="button button-primary header-cta" href="{{ route('register') }}">Crear mi reto</a>
            <a href="{{ route('login') }}">Entrar</a>
        </div>
    </header>

    <main id="contenido">
        <section class="hero section" id="inicio" aria-labelledby="hero-title">
            <div class="container hero-grid">
                <div class="hero-copy">
                    <p class="eyebrow">Fidelización que se siente cercana</p>
                    <h1 id="hero-title">Haz que volver sea parte del juego.</h1>
                    <p class="lead">Crea retos de visitas, añade la tarjeta a Google Wallet y recompensa a tus clientes cuando los completan.</p>
                    <div class="actions">
                        <a class="button button-primary" href="{{ route('register') }}">Crear mi reto</a>
                        <a class="button button-outline" href="#como-funciona">Ver cómo funciona</a>
                    </div>
                    <p class="small-note">El primer paso es registrar tu cuenta Business.</p>
                </div>
                <div class="hero-visual" aria-label="Ejemplo ilustrativo de tarjeta de puntos" role="img">
                    <div class="wallet-card" aria-hidden="true">
                        <div class="card-top"><span>FidelitoPass</span><span class="card-symbol">✳</span></div>
                        <p class="card-label">CAFÉ DEL BARRIO · RETO ACTUAL</p>
                        <strong>6 de 10 puntos</strong>
                        <div class="progress-track"><span></span></div>
                        <p>Tu próxima visita te acerca a un café de regalo.</p>
                        <div class="card-bottom"><span>Una tarjeta para seguir sumando</span><span>● ● ●</span></div>
                    </div>
                    <span class="visual-tag" aria-hidden="true">Cada visita cuenta ↗</span>
                </div>
            </div>
        </section>

        <section class="section section-soft" id="como-funciona" aria-labelledby="steps-title">
            <div class="container">
                <p class="eyebrow">Así de simple</p>
                <h2 id="steps-title">De la primera visita a la recompensa.</h2>
                <p class="section-intro">Tu negocio propone el reto; tus clientes ven su progreso en cada visita.</p>
                <ol class="steps">
                    <li><span class="step-icon" aria-hidden="true">✦</span><span class="step-number">01</span><h3>Crea un reto</h3><p>Define una meta de puntos, una fecha límite y la recompensa.</p></li>
                    <li><span class="step-icon" aria-hidden="true">▦</span><span class="step-number">02</span><h3>Comparte tu QR</h3><p>El cliente escanea el QR de tu negocio y guarda su tarjeta.</p></li>
                    <li><span class="step-icon" aria-hidden="true">✓</span><span class="step-number">03</span><h3>Valida visitas</h3><p>Confirma cada visita y suma los puntos que correspondan.</p></li>
                    <li><span class="step-icon" aria-hidden="true">★</span><span class="step-number">04</span><h3>Entrega la recompensa</h3><p>Al alcanzar la meta, la recompensa queda disponible para canjear.</p></li>
                </ol>
            </div>
        </section>

        <section class="section" id="retos" aria-labelledby="challenge-title">
            <div class="container split-grid">
                <div>
                    <p class="eyebrow">Retos con un objetivo claro</p>
                    <h2 id="challenge-title">Puntos que invitan a volver.</h2>
                    <p class="section-intro">Consigue puntos antes de una fecha y desbloquea una recompensa. Cada visita acerca a tus clientes a una meta fácil de entender.</p>
                    <p>También puedes hacer que un momento especial valga más puntos, sin complicar las reglas.</p>
                </div>
                <div class="example-panel">
                    <p class="eyebrow">Un ejemplo concreto</p>
                    <h3>Un café de regalo al llegar a 10 puntos</h3>
                    <ul class="example-list">
                        <li><span>Visita habitual</span><strong>1 punto</strong></li>
                        <li><span>Visita los martes</span><strong>2 puntos</strong></li>
                        <li><span>Meta del reto</span><strong>10 puntos</strong></li>
                    </ul>
                    <p class="example-note">Ejemplo ilustrativo: el negocio define la vigencia y la recompensa de cada reto.</p>
                </div>
            </div>
        </section>

        <section class="section section-soft" id="wallet" aria-labelledby="wallet-title">
            <div class="container split-grid">
                <div>
                    <p class="eyebrow">Siempre a mano</p>
                    <h2 id="wallet-title">Una sola tarjeta. Nuevos retos con el tiempo.</h2>
                    <p class="section-intro">El cliente guarda una tarjeta permanente del negocio en Google Wallet. Cuando llega un nuevo reto, la misma tarjeta muestra el estado actual: puntos, meta y recompensa.</p>
                </div>
                <div class="wallet-details" aria-label="Información de la tarjeta">
                    <p>La tarjeta muestra</p>
                    <ul>
                        <li>El nombre del negocio</li>
                        <li>Los puntos acumulados y la meta actual</li>
                        <li>La recompensa y su vigencia</li>
                    </ul>
                    <strong>Una tarjeta para seguir volviendo.</strong>
                </div>
            </div>
        </section>

        <section class="section business-section" id="negocios" aria-labelledby="business-title">
            <div class="container business-inner">
                <p class="eyebrow">Para negocios</p>
                <h2 id="business-title">Convierte cada visita en una razón para regresar.</h2>
                <p>Imagina una recompensa para tus clientes y un reto de puntos para sus visitas. FidelitoPass acompaña el recorrido desde la primera visita hasta el canje.</p>
                <a class="button button-primary" href="{{ route('register') }}">Crea tu primer reto</a>
                <p class="small-note">Primero crea una cuenta Business para empezar.</p>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container footer-inner"><span class="wordmark">Fidelito<span>Pass</span></span><p>Visitas que suman. Recompensas que acercan.</p><a href="{{ route('register') }}">Registrarse</a><a href="{{ route('login') }}">Entrar</a><a href="#inicio">Volver al inicio ↑</a></div>
    </footer>
</body>
</html>
