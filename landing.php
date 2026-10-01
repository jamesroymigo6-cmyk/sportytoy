<?php
require 'config/db.php';
require 'config/auth.php';

// Signed-in members go straight to the workspace.
if (current_user()) {
    header('Location: index.php');
    exit;
}

$clerkOn = sportsync_clerk_enabled();
$authNote = $clerkOn ? 'Verified email sign-in powered by Clerk' : 'Secure account access';
$notice = trim($_GET['notice'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#8b2ff5">
<meta name="description" content="Sporty Ni Migo — the online sports event operations platform for Tupi, South Cotabato. Plan events, reserve venues and equipment, run the shop, and keep the community connected.">
<title>Sporty Ni Migo · Sports Event Operations Platform</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link rel="icon" type="image/png" href="assets/img/logo-icon.png"><link rel="stylesheet" href="assets/css/landing.css?v=20261002a">
</head>
<body class="landing-body">

<div class="frame">

<?php if ($notice): ?>
<div class="landing-toast" role="status"><i class="fa-solid fa-circle-info"></i><span><?= e($notice) ?></span></div>
<?php endif; ?>

    <!-- ============ NAV ============ -->
    <header class="landing-nav" id="landingNav">
        <a class="landing-brand" href="landing.php">
            <span class="landing-brand-mark"><img src="assets/img/logo-icon.png" alt="Sporty Ni Migo logo"></span>
            <span class="brand-words"><strong>Sporty Ni Migo</strong><small>sports operations</small></span>
        </a>
        <nav class="landing-links" aria-label="Site sections">
            <a href="#features">Features</a>
            <a href="#how">How&nbsp;it&nbsp;works</a>
            <a href="#venues">Venues</a>
            <a href="#faq">FAQ</a>
        </nav>
        <div class="landing-nav-actions">
            <a class="btn-pill btn-pill-solid btn-pill-sm" href="register.php">Sign up</a>
            <button class="landing-burger" id="landingBurger" type="button" aria-label="Open menu" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
        </div>
        <div class="landing-mobile-menu" id="landingMobileMenu">
            <a href="#features">Features</a>
            <a href="#how">How it works</a>
            <a href="#venues">Venues</a>
            <a href="#faq">FAQ</a>
            <a href="login.php">Sign in</a>
            <a class="cta" href="register.php">Sign up</a>
        </div>
    </header>

    <main>
        <!-- ============ HERO ============ -->
        <section class="hero">
            <div class="hero-grid">
                <div class="hero-left">
                    <h1 class="hero-title">Welcome<span class="dot">.</span></h1>
                    <form class="hero-search" id="heroSearch" role="search" autocomplete="off">
                        <input type="search" id="heroSearchInput" placeholder="Search events, venues, gear…" aria-label="Quick find">
                        <button type="submit" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></button>
                    </form>
                    <div class="hero-ctas">
                        <a class="btn-pill btn-pill-solid" href="register.php">Get started</a>
                        <a class="btn-pill btn-pill-line" href="#how">How it works</a>
                    </div>
                    <div class="hero-trust">
                        <span><i class="fa-solid fa-circle-check"></i> <?= e($authNote) ?></span>
                        <span><i class="fa-solid fa-circle-check"></i> Free community accounts</span>
                    </div>
                </div>

                <div class="hero-right">
                    <svg class="wave-art" viewBox="0 0 520 520" aria-hidden="true" focusable="false">
                        <defs>
                            <linearGradient id="waveGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%"  stop-color="#c4b5fd"/>
                                <stop offset="45%" stop-color="#8b5cf6"/>
                                <stop offset="100%" stop-color="#60a5fa"/>
                            </linearGradient>
                            <filter id="wavy" x="-30%" y="-30%" width="160%" height="160%">
                                <feTurbulence type="fractalNoise" baseFrequency="0.012 0.02" numOctaves="2" seed="7" result="noise"/>
                                <feDisplacementMap in="SourceGraphic" in2="noise" scale="58"/>
                            </filter>
                        </defs>
                        <g filter="url(#wavy)" fill="none" stroke="url(#waveGrad)" stroke-width="1.6">
                            <circle cx="260" cy="260" r="42"  opacity="0.95"/>
                            <circle cx="260" cy="260" r="66"  opacity="0.9"/>
                            <circle cx="260" cy="260" r="90"  opacity="0.85"/>
                            <circle cx="260" cy="260" r="114" opacity="0.8"/>
                            <circle cx="260" cy="260" r="138" opacity="0.72"/>
                            <circle cx="260" cy="260" r="162" opacity="0.62"/>
                            <circle cx="260" cy="260" r="186" opacity="0.52"/>
                            <circle cx="260" cy="260" r="210" opacity="0.4"/>
                            <circle cx="260" cy="260" r="234" opacity="0.28"/>
                        </g>
                        <g filter="url(#wavy)" fill="none" stroke="url(#waveGrad)" stroke-width="1.1" transform="translate(310 330) scale(0.45)" opacity="0.55">
                            <circle cx="0" cy="0" r="46"/>
                            <circle cx="0" cy="0" r="78"/>
                            <circle cx="0" cy="0" r="110"/>
                            <circle cx="0" cy="0" r="142"/>
                            <circle cx="0" cy="0" r="174"/>
                        </g>
                    </svg>
                    <div class="hero-side">
                        <i class="fa-solid fa-trophy side-icon"></i>
                        <h2>sports operations<span class="dot">.</span></h2>
                        <p>One connected workspace for community sports — events, venues, equipment, the shop, announcements, and every conversation, online and in sync.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============ FEATURES ============ -->
        <section class="landing-section" id="features">
            <div class="section-head reveal">
                <span class="eyebrow">Everything in one place</span>
                <h2>Built for the whole season<span class="dot">.</span></h2>
                <p>Sporty Ni Migo keeps the community moving — from the first plan to the final whistle.</p>
            </div>
            <div class="feature-grid">
                <article class="feature-card reveal">
                    <div class="feature-icon blue"><i class="fa-solid fa-calendar-days"></i></div>
                    <h3>Event planning &amp; scheduling</h3>
                    <p>Pick a date, choose a venue, borrow equipment — Sporty Ni Migo checks every conflict before it happens and reserves everything at once.</p>
                </article>
                <article class="feature-card reveal">
                    <div class="feature-icon green"><i class="fa-solid fa-map-location-dot"></i></div>
                    <h3>Tupi venue maps</h3>
                    <p>Discover local venues with capacity, facilities, and live map markers, then check availability for your exact schedule.</p>
                </article>
                <article class="feature-card reveal">
                    <div class="feature-icon orange"><i class="fa-solid fa-dumbbell"></i></div>
                    <h3>Equipment reservations</h3>
                    <p>Borrow gear with your event plan or from the shop — quantities, schedules, and returns stay tracked automatically.</p>
                </article>
                <article class="feature-card reveal">
                    <div class="feature-icon purple"><i class="fa-solid fa-bag-shopping"></i></div>
                    <h3>Shop &amp; checkout</h3>
                    <p>Merchandise and supplies with a cart, stock control, and Cash, GCash, or Card checkout — orders sync with your events.</p>
                </article>
                <article class="feature-card reveal">
                    <div class="feature-icon blue"><i class="fa-solid fa-comments"></i></div>
                    <h3>Messages &amp; voice notes</h3>
                    <p>A Messenger-style portal for direct messages and recorded voice clips — staff, organizers, and athletes in one thread.</p>
                </article>
                <article class="feature-card reveal">
                    <div class="feature-icon red"><i class="fa-solid fa-bullhorn"></i></div>
                    <h3>Announcements &amp; urgent SMS</h3>
                    <p>Publish targeted notices and blast urgent or emergency updates straight to phones through SMSGate SMS.</p>
                </article>
                <article class="feature-card reveal">
                    <div class="feature-icon purple"><i class="fa-solid fa-people-group"></i></div>
                    <h3>Community wall</h3>
                    <p>Share event and tournament updates with photos, likes, and comments — the community story lives in one feed.</p>
                </article>
                <article class="feature-card reveal">
                    <div class="feature-icon green"><i class="fa-solid fa-trophy"></i></div>
                    <h3>Tournament brackets</h3>
                    <p>Follow competitions in list, bracket, or schedule views so every team and supporter knows what is next.</p>
                </article>
            </div>
        </section>

        <!-- ============ HOW IT WORKS ============ -->
        <section class="landing-section landing-section-alt" id="how">
            <div class="section-head reveal">
                <span class="eyebrow">Simple by design</span>
                <h2>Three steps to game day<span class="dot">.</span></h2>
                <p>Get from sign-up to a fully reserved, fully announced event in minutes.</p>
            </div>
            <div class="steps-grid">
                <article class="step-card reveal">
                    <span class="step-num">1</span>
                    <i class="fa-solid fa-envelope-circle-check"></i>
                    <h3>Create your account</h3>
                    <p>Register with your email and verify it —<?= $clerkOn ? ' a one-time code confirms it is really you.' : ' your credentials stay protected with lockout protection.' ?></p>
                </article>
                <article class="step-card reveal">
                    <span class="step-num">2</span>
                    <i class="fa-solid fa-calendar-plus"></i>
                    <h3>Plan or join an event</h3>
                    <p>Reserve a venue and equipment with conflict checking, or browse upcoming events and register as a participant.</p>
                </article>
                <article class="step-card reveal">
                    <span class="step-num">3</span>
                    <i class="fa-solid fa-trophy"></i>
                    <h3>Play &amp; stay connected</h3>
                    <p>Get reminders, weather awareness, announcements, and community updates — before, during, and after game day.</p>
                </article>
            </div>
        </section>

        <!-- ============ VENUES ============ -->
        <section class="landing-section" id="venues">
            <div class="section-head reveal">
                <span class="eyebrow">Tupi, South Cotabato</span>
                <h2>Venues ready for your next game<span class="dot">.</span></h2>
                <p>Real community venues with capacity, facilities, and live availability inside the app.</p>
            </div>
            <div class="venue-grid">
                <article class="venue-card reveal">
                    <div class="venue-pin"><i class="fa-solid fa-location-dot"></i></div>
                    <h3>Tupi Municipal Gymnasium</h3>
                    <p><i class="fa-solid fa-users"></i> 3,000 capacity · covered court, bleachers, first-aid area</p>
                </article>
                <article class="venue-card reveal">
                    <div class="venue-pin"><i class="fa-solid fa-location-dot"></i></div>
                    <h3>Tupi Community Sports Field</h3>
                    <p><i class="fa-solid fa-users"></i> 1,500 capacity · open field, lighting, staging area</p>
                </article>
                <article class="venue-card reveal">
                    <div class="venue-pin"><i class="fa-solid fa-location-dot"></i></div>
                    <h3>Tupi Training &amp; Activity Center</h3>
                    <p><i class="fa-solid fa-users"></i> 800 capacity · indoor area, changing rooms, parking</p>
                </article>
            </div>
        </section>

        <!-- ============ FAQ ============ -->
        <section class="landing-section landing-section-alt" id="faq">
            <div class="section-head reveal">
                <span class="eyebrow">Good to know</span>
                <h2>Frequently asked questions<span class="dot">.</span></h2>
            </div>
            <div class="faq-list">
                <details class="faq-item reveal" open>
                    <summary>Is Sporty Ni Migo free to use? <i class="fa-solid fa-chevron-down"></i></summary>
                    <p>Yes. Participant and community accounts are free — register with your email, verify it, and you can plan, join, and shop right away.</p>
                </details>
                <details class="faq-item reveal">
                    <summary>How do I sign in? <i class="fa-solid fa-chevron-down"></i></summary>
                    <p><?= $clerkOn ? 'Sign in with your email — Clerk verifies you with a secure code before opening your workspace. Email and password remain available as a fallback.' : 'Sign in with your registered email and password. Five failed attempts trigger a short lockout to keep accounts safe.' ?></p>
                </details>
                <details class="faq-item reveal">
                    <summary>Can I reserve a venue and equipment together? <i class="fa-solid fa-chevron-down"></i></summary>
                    <p>Yes — that is the point of the planner. Availability is re-checked at submission so nothing can be double-booked.</p>
                </details>
                <details class="faq-item reveal">
                    <summary>Will I get urgent updates? <i class="fa-solid fa-chevron-down"></i></summary>
                    <p>Add your mobile number during registration and urgent or emergency announcements can reach you by SMS, in-app notification, or both.</p>
                </details>
            </div>
        </section>

        <!-- ============ CTA ============ -->
        <section class="landing-cta reveal">
            <div class="cta-card">
                <span class="eyebrow">Ready when you are</span>
                <h2>Your community's next event starts here<span class="dot">.</span></h2>
                <p>Create a free account in under a minute — verify your email and open your Sporty Ni Migo workspace.</p>
                <div class="hero-ctas center">
                    <a class="btn-pill btn-pill-solid" href="register.php">Create your account</a>
                    <a class="btn-pill btn-pill-line" href="login.php">I already have one</a>
                </div>
            </div>
        </section>
    </main>

    <footer class="landing-footer">
        <div class="footer-inner">
            <div class="footer-brand">
                <span class="landing-brand-mark"><img src="assets/img/logo-icon.png" alt="Sporty Ni Migo logo"></span>
                <div class="brand-words"><strong>Sporty Ni Migo</strong><small>Sports event operations for Tupi, South Cotabato</small></div>
            </div>
            <nav class="footer-links" aria-label="Footer">
                <a href="login.php">Sign in</a>
                <a href="register.php">Sign up</a>
                <a href="#features">Features</a>
                <a href="#faq">FAQ</a>
            </nav>
            <small class="footer-copy">© <?= date('Y') ?> Sporty Ni Migo. Built for the community.</small>
        </div>
    </footer>

</div><!-- /.frame -->

<script>
(function () {
    // Sticky nav state on scroll
    var nav = document.getElementById('landingNav');
    var onScroll = function () { nav.classList.toggle('scrolled', window.scrollY > 12); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    // Mobile menu
    var burger = document.getElementById('landingBurger');
    var menu = document.getElementById('landingMobileMenu');
    burger?.addEventListener('click', function () {
        var open = menu.classList.toggle('open');
        burger.setAttribute('aria-expanded', open ? 'true' : 'false');
        burger.innerHTML = open ? '<i class="fa-solid fa-xmark"></i>' : '<i class="fa-solid fa-bars"></i>';
    });
    menu?.querySelectorAll('a').forEach(function (a) {
        a.addEventListener('click', function () {
            menu.classList.remove('open');
            burger.setAttribute('aria-expanded', 'false');
            burger.innerHTML = '<i class="fa-solid fa-bars"></i>';
        });
    });

    // Hero quick-find: map keywords to a section and scroll there.
    var form = document.getElementById('heroSearch');
    var input = document.getElementById('heroSearchInput');
    var map = [
        { words: ['venue', 'gym', 'court', 'field', 'map', 'location'], target: '#venues' },
        { words: ['event', 'plan', 'schedule', 'calendar', 'register', 'join', 'tournament'], target: '#how' },
        { words: ['shop', 'equipment', 'gear', 'merch', 'price', 'buy', 'cart'], target: '#features' },
        { words: ['sms', 'announce', 'message', 'voice', 'community'], target: '#features' },
        { words: ['account', 'sign', 'login', 'password', 'cost', 'free', 'faq'], target: '#faq' }
    ];
    form?.addEventListener('submit', function (e) {
        e.preventDefault();
        var q = (input?.value || '').toLowerCase().trim();
        var target = '#features';
        if (q) {
            for (var i = 0; i < map.length; i++) {
                for (var j = 0; j < map[i].words.length; j++) {
                    if (q.indexOf(map[i].words[j]) !== -1) { target = map[i].target; break; }
                }
                if (target !== '#features') break;
            }
        }
        var el = document.querySelector(target);
        el?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    // Reveal-on-scroll
    var revealEls = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
            });
        }, { threshold: 0.12 });
        revealEls.forEach(function (el) { io.observe(el); });
    } else {
        revealEls.forEach(function (el) { el.classList.add('in'); });
    }
})();
</script>
</body>
</html>
