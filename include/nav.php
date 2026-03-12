<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!-- Top Bar -->
<div class="bbcc-topbar">
    <div class="bbcc-container bbcc-topbar__inner">
        <div class="bbcc-topbar__info">
            <span><i class="fa-solid fa-envelope"></i> bbbccc@gmail.com</span>
            <span><i class="fa-solid fa-phone"></i> 0420 942 340</span>
        </div>
        <div class="bbcc-topbar__social">
            <a href="https://www.facebook.com/profile?id=100084018901076" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
            <a href="#" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a>
            <a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
        </div>
    </div>
</div>

<!-- Main Navigation -->
<nav class="bbcc-navbar" id="bbccNavbar">
    <div class="bbcc-container bbcc-navbar__inner">

        <!-- Brand -->
        <a href="index" class="bbcc-navbar__brand">
            <img src="bbccassests/img/logo/logo5.jpg" alt="BBCC Logo">
            <div class="bbcc-navbar__brand-text">
                Bhutanese Buddhist &amp; Cultural Center
                <small>Canberra, Australian Capital Territory</small>
            </div>
        </a>

        <!-- Nav Links -->
        <ul class="bbcc-nav" id="bbccNav">
            <li class="<?= ($currentPage === 'index.php') ? 'active' : '' ?>">
                <a href="index">Home</a>
            </li>
            <li class="<?= ($currentPage === 'about-us.php') ? 'active' : '' ?>">
                <a href="about-us">About</a>
            </li>
            <li class="<?= ($currentPage === 'services.php') ? 'active' : '' ?>">
                <a href="services">Services</a>
            </li>
            <li class="<?= ($currentPage === 'events.php' || $currentPage === 'event_detail.php' || $currentPage === 'book-event.php') ? 'active' : '' ?>">
                <a href="events">Events</a>
            </li>
            <li class="<?= ($currentPage === 'contact-us.php') ? 'active' : '' ?>">
                <a href="contact-us">Contact</a>
            </li>
            <li>
                <a href="login" class="bbcc-nav__login">
                    <i class="fa-solid fa-right-to-bracket"></i> Login
                </a>
            </li>
        </ul>

        <!-- Mobile Toggle -->
        <button class="bbcc-navbar__toggle" id="bbccNavToggle" aria-label="Toggle navigation">
            <i class="fa-solid fa-bars"></i>
        </button>

    </div>
</nav>

<script>
document.getElementById('bbccNavToggle').addEventListener('click', function() {
    document.getElementById('bbccNav').classList.toggle('open');
    const icon = this.querySelector('i');
    icon.classList.toggle('fa-bars');
    icon.classList.toggle('fa-xmark');
});
</script>
