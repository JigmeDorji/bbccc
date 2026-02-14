<?php
echo '
<!-- Favicon
		============================================ -->
    <link rel="shortcut icon" type="image/x-icon" href="bbccassests/image/favicon.ico">

    <!-- CSS  -->
    <!-- Venobox CSS
		============================================ -->
    <link rel="stylesheet" href="bbccassests/venobox/venobox.css" type="text/css" media="screen" />

    <!-- Bootstrap CSS
		============================================ -->
    <link rel="stylesheet" href="bbccassests/css/bootstrap.min.css">

    <!-- owl.carousel CSS
		============================================ -->
    <link rel="stylesheet" href="bbccassests/css/meanmenu.min.css">
    <link rel="stylesheet" href="bbccassests/css/owl.carousel.css">

    <!-- owl.theme CSS
		============================================ -->
    <link rel="stylesheet" href="bbccassests/css/owl.theme.css">

    <!-- owl.transitions CSS
		============================================ -->
    <link rel="stylesheet" href="bbccassests/css/owl.transitions.css">
    <!-- nivo-slider css -->
    <link rel="stylesheet" href="bbccassests/css/nivo-slider.css">
    <!-- font-awesome.min CSS
		============================================ -->
    <link rel="stylesheet" href="bbccassests/css/font-awesome.min.css">

    <!-- animate CSS
		============================================ -->
    <link rel="stylesheet" href="bbccassests/css/animate.css">

    <!-- normalize CSS
		============================================ -->
    <link rel="stylesheet" href="bbccassests/css/normalize.css">

    <!-- main CSS
		============================================ -->
    <link rel="stylesheet" href="bbccassests/css/main.css">

    <!-- style CSS
		============================================ -->
    <link rel="stylesheet" href="bbccassests/style.css">

    <!-- responsive CSS
		============================================ -->
    <link rel="stylesheet" href="bbccassests/css/responsive.css">

    <script src="bbccassests/js/vendor/modernizr-2.8.3.min.js"></script>
    
    <style>
     :root {
            --primary-color: #881b12; /* Updated primary color */
            --dark-color: #222;
            --light-bg: #f8f9fa;
            --card-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --border-radius: 10px;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--light-bg);
            color: #444;
        }

        /* Hero Section */
        .hero_brd_area {
            background: linear-gradient(rgba(0, 0, 0, 0.6), rgba(0, 0, 0, 0.6)), url(bbccassests/img/slider/ParoTaktsang.png);
            background-size: cover;
            background-position: center;
            height: 300px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #fff;
            position: relative;
        }

        .hero_content {
            z-index: 1;
        }

        .hero_content h2 {
            font-size: 3.5rem;
            margin: 0;
            font-weight: 700;
        }

        .hero_content ul {
            padding: 0;
            margin: 0;
            list-style: none;
            display: flex;
            justify-content: center;
        }

        .hero_content ul li {
            font-size: 1rem;
            margin: 0 10px;
        }

        .hero_content ul li a {
            color: #fff;
            text-decoration: none;
        }

        /* General Section Styling */
        .section_padding {
            padding: 80px 0;
        }

        .section_title {
            text-align: center;
            margin-bottom: 50px;
        }

        .section_title h2 {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 10px;
        }

        .section_title span {
            color: var(--primary-color);
        }

        /* About Us Main Content */
        .about_us_content {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            padding: 50px;
            border-radius: var(--border-radius);
            background: #fff;
            box-shadow: var(--card-shadow);
        }

        .about_us_content .img-col, .about_us_content .text-col {
            flex: 1 1 50%;
            padding: 20px;
        }

        .about_us_content img {
            max-width: 100%;
            height: auto;
            border-radius: var(--border-radius);
        }

        .about_us_content h3 {
            font-size: 2rem;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 20px;
        }
</style>

<!-- Home pages css  -->
<style>
    /* Styling for the unique single image container */
    .about_single_image {
        position: relative;
        width: 90%; /* Adjust width as needed */
        height: 400px; /* Fixed height for consistent look */
        margin: 30px auto; /* Center the image and provide some vertical space */
        overflow: hidden;
        border-radius: 10px; /* Soften the corners */
        box-shadow: 0 15px 40px rgba(0, 0, 0, 0.2); /* Stronger shadow for depth */
        transform: rotate(-5deg); /* Rotate the image container */
        transition: transform 0.5s ease-in-out;
    }

    .about_single_image:hover {
        transform: rotate(0deg) scale(1.02); /* Straighten and slightly enlarge on hover */
    }

    .about_single_image img {
        width: 100%;
        height: 100%;
        object-fit: cover; /* Ensure image covers the area */
        display: block;
        transform: rotate(5deg); /* Counter-rotate the image to appear straight inside the rotated container */
        transition: transform 0.5s ease-in-out;
    }

    .about_single_image:hover img {
        transform: rotate(0deg); /* Straighten image on container hover */
    }

    /* Responsive adjustments */
    @media screen and (max-width: 767px) {
        .about_single_image {
            width: 100%;
            height: 300px;
            margin: 20px 0;
            transform: rotate(0deg); /* Disable rotation on smaller screens for better fit */
        }
        .about_single_image:hover {
            transform: scale(1.02);
        }
        .about_single_image img {
            transform: rotate(0deg);
        }
    }
.hero-slider {
    position: relative;
    width: 100%;
    height: 520px;
    overflow: hidden;
}

.hero-slide {
    position: absolute;
    top: 0; left: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
}

.hero-slide.active {
    opacity: 1;
}

/* HERO CONTAINER */
.hero-container {
    width: 100%;
    height: 520px;
    display: flex;
    background: #ffffff;
}

.hero-left {
    width: 55%;
    padding: 80px 60px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

/* Fly in animation */
@keyframes flyIn {
    0% { opacity: 0; transform: translateY(30px); }
    100% { opacity: 1; transform: translateY(0); }
}

.hero-left h1,
.hero-left p,
.hero-left .hero-btn {
    opacity: 0;
}

.hero-slide.active .hero-left h1 {
    animation: flyIn 0.6s forwards;
    animation-delay: 0.2s;
}

.hero-slide.active .hero-left p {
    animation: flyIn 0.6s forwards;
    animation-delay: 0.5s;
}

.hero-slide.active .hero-left .hero-btn {
    animation: flyIn 0.6s forwards;
    animation-delay: 0.8s;
}

/* Text Styling */
.hero-left h1 {
    font-size: 48px;
    font-weight: 800;
    color: #7a1f16;
    line-height: 1.15;
    margin: 0;
}

.hero-left p {
    margin-top: 25px;
    font-size: 18px;
    max-width: 550px;
    line-height: 1.6;
}

.hero-btn {
    margin-top: 35px;
    background: #7a1f16;
    color: #fff;
    padding: 15px 35px;
    display: inline-block;
    font-size: 18px;
    font-weight: 700;
    text-decoration: none;
    border-radius: 4px;
}

.hero-btn:hover {
    background: #a72b20;
}

/* Right Image */
.hero-right {
    width: 45%;
    clip-path: polygon(20% 0, 100% 0, 100% 100%, 0% 100%);
}

.hero-right img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: 0;
}

/* Slow image zoom-out animation */
@keyframes imageZoomOut {
    0% {
        opacity: 0;
        transform: scale(1.2);
    }
    100% {
        opacity: 1;
        transform: scale(1);
    }
}

.hero-slide.active .hero-right img {
    animation: imageZoomOut 2s ease-out forwards;
    animation-delay: 0.3s;
}

/* Slider Counter (dots) */
.slider-counter {
    position: absolute;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 12px;
    z-index: 10;
}

.slider-counter .dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background:gray;
    cursor: pointer;
    transition: background 0.3s, transform 0.2s;
}

.slider-counter .dot:hover {
    transform: scale(1.2);
    background: #7a1f16;
}

.slider-counter .dot.active {
    background: #7a1f16;
}

/* ---------------- Tablet + Mobile (Unified Layout) ---------------- */
@media (max-width: 992px) {

    /* Slider height – auto so content does not overflow */
    .hero-slider {
        height: auto;
        min-height: 300px;
    }

    /* Stack content vertically */
    .hero-container {
        height: auto;
        min-height: 300px;
        flex-direction: column;
    }

    /* Text area */
    .hero-left {
        width: 100%;
        padding: 30px 20px;
        text-align: center;
        justify-content: center;
    }

    .hero-left h1 {
        font-size: 28px;
    }

    .hero-left p {
        font-size: 15px;
        margin-top: 12px;
        max-width: 100%;
    }

    .hero-btn {
        margin: 15px auto 0 auto;
        padding: 10px 24px;
        font-size: 15px;
    }

    .read_more_btn {
        text-align: center;
    }

    /* Image section */
    .hero-right {
        width: 100%;
        clip-path: none;
    }

    .hero-right img {
        width: 100%;
        height: 200px;
        object-fit: cover;
    }

    /* Slider dots */
    .slider-counter {
        bottom: 8px;
        gap: 8px;
    }

    .slider-counter .dot {
        width: 10px;
        height: 10px;
    }

    /* Slide positioning – let them flow naturally when active */
    .hero-slide {
        position: relative;
        display: none;
    }
    .hero-slide.active {
        display: block;
        position: relative;
    }
}

/* ---------------- Mobile Optimization (extra small screens) ---------------- */
@media (max-width: 576px) {

    .hero-left {
        padding: 20px 15px;
    }

    .hero-left h1 {
        font-size: 22px;
    }

    .hero-left p {
        font-size: 13px;
    }

    .hero-right img {
        height: 180px;
    }
}

.blog_thumb img {
    width: 220px;      /* fixed width */
    height: 299px;     /* fixed height */
    object-fit: cover; /* crop image without stretching */
    object-position: center;
    display: block;
}



</style>


'
?>


