<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<style>
    .logo {
        display: flex;
        align-items: center;
        margin-top: -27%;
        margin-bottom: -26%;
        width: 180px;
        height: 180px;
        object-fit: contain;
    }
    .logo img {
        margin-right: 8px;
    }
    .logo h3 {
        margin: 0;
    }

    /* Active Menu */
    .navid li.active > a {
        color: #881b12 !important;
        font-weight: bold;
        border-bottom: 2px solid #881b12;
    }

    /* Login button */
    .login-button a {
        display: flex;
        justify-content: center;
        align-items: center;
        text-align: center;
        border: 2px solid #881b12;
        padding: 8px 16px;
        border-radius: 5px;
        color: #881b12;
        font-weight: bold;
        text-transform: uppercase;
        transition: 0.3s;
    }

    .login-button a:hover {
        background-color: #6b140d;
        color: #fff;
        text-decoration: none;
    }

    /* ── Mobile header top bar ────────────────────── */
    @media (max-width: 990px) {
        .header_top .col-xs-12.col-md-5 p {
            text-align: center;
            margin-bottom: 5px;
        }
        .header_top .col-xs-12.col-md-5 p span {
            display: inline-block;
            margin: 0 6px 4px;
            font-size: 13px;
        }
        .header_top .social-icons {
            text-align: center;
            margin: 0;
            padding: 4px 0 8px;
        }

        /* Mobile logo + name above hamburger */
        .mobile-logo-bar {
            display: flex !important;
            align-items: center;
            background: #fff;
            padding: 10px 15px;
        }
        .mobile-logo-bar img {
            width: 50px;
            height: 50px;
            border-radius: 6px;
            margin-right: 10px;
            object-fit: contain;
        }
        .mobile-logo-bar h4 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #222;
        }
    }
    @media (min-width: 991px) {
        .mobile-logo-bar { display: none !important; }
    }
</style>

<!-- Header Top -->
<div class="header_top">
    <div class="container">
        <div class="row">
            <div class="col-xs-12 col-md-5 col-sm-6">
                <p>
                    <span><i class="fa fa-user white"></i> bbbccc@gmail.com</span>
                    <span><i class="fa fa-phone"></i> 0404902044</span>
                </p>
            </div>

            <div class="col-xs-12 col-md-3 col-sm-6"></div>

            <div class="col-xs-12 col-md-4 col-sm-12">
                <ul class="social-icons">
                    <li><a class="facebook" href="#"><i class="fa fa-facebook"></i></a></li>
                    <li><a class="rss" href="#"><i class="fa fa-youtube"></i></a></li>
                    <li><a class="google" href="#"><i class="fa fa-instagram"></i></a></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Desktop Navigation -->
<div class="nav_areas hidden-xs hidden-sm">
    <div class="nav_area">
        <div class="container">
            <div class="row">

                <!-- Logo -->
                <div class="col-md-3 col-sm-4 col-xs-5">
                    <div class="logo">
                        <a href="index.php"><img src="bbccassests/img/logo/logo5.jpg" alt=""></a>
                        <h3>Bhutanese Centre Canberra</h3>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="col-md-9 col-sm-8 col-xs-8">
                    <nav class="menu">
                        <ul class="navid">

                            <li class="<?= ($currentPage === 'index.php') ? 'active' : '' ?>">
                                <a href="index.php">Home</a>
                            </li>

                            <li class="<?= ($currentPage === 'about-us.php') ? 'active' : '' ?>">
                                <a href="about-us.php">About</a>
                            </li>

                            <li class="<?= ($currentPage === 'services.php') ? 'active' : '' ?>">
                                <a href="services.php">Service</a>
                            </li>

                            <li class="<?= ($currentPage === 'events.php' || $currentPage === 'book-event.php') ? 'active' : '' ?>">
                                <a href="events.php">Events</a>
                            </li>

                            <li class="<?= ($currentPage === 'contact-us.php') ? 'active' : '' ?>">
                                <a href="contact-us.php">Contact</a>
                            </li>

                            <li class="">
                                <a href="login.php">
                                    <button class="btn btn-sm btn-danger">Login</button>
                                </a>
                            </li>

                        </ul>
                    </nav>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Mobile Logo Bar (visible only on mobile, above hamburger menu) -->
<div class="mobile-logo-bar hidden-lg hidden-md">
    <a href="index.php"><img src="bbccassests/img/logo/logo5.jpg" alt="BBCC Logo"></a>
    <h4>Bhutanese Centre Canberra</h4>
</div>

<!-- Mobile Menu (meanmenu takes over this nav) -->
<div class="nav_areas hidden-lg hidden-md">
    <div class="mobile-menu">
        <div class="container">
            <div class="row">
                <div class="col-sm-12 col-xs-12">

                    <nav class="menu">
                        <ul>

                            <li class="<?= ($currentPage === 'index.php') ? 'active' : '' ?>">
                                <a href="index.php">Home</a>
                            </li>

                            <li class="<?= ($currentPage === 'about-us.php') ? 'active' : '' ?>">
                                <a href="about-us.php">About</a>
                            </li>

                            <li class="<?= ($currentPage === 'services.php') ? 'active' : '' ?>">
                                <a href="services.php">Service</a>
                            </li>

                            <li class="<?= ($currentPage === 'events.php' || $currentPage === 'book-event.php') ? 'active' : '' ?>">
                                <a href="events.php">Events</a>
                            </li>

                            <li class="<?= ($currentPage === 'contact-us.php') ? 'active' : '' ?>">
                                <a href="contact-us.php">Contact</a>
                            </li>

                            <li>
                                <a href="login.php">Login</a>
                            </li>

                        </ul>
                    </nav>

                </div>
            </div>
        </div>
    </div>
</div>
