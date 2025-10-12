<?php
echo '

<head>

</head>
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
        margin-right: 8px; /* space between logo and text */
    }
    .logo h3 {
        margin: 0;
    }
   /* Styling for the login button only */
.login-button a {
    display: flex;
    justify-content: center;
    align-items: center;
    text-align: center;
    border: 2px solid #881b12; /* Your primary color */
    padding: 8px 16px;
    border-radius: 5px;
    color: #881b12; /* Use your primary color for the text */
    font-weight: bold;
    text-transform: uppercase;
    transition: all 0.3s ease;
}

/* Hover Option 1: Change Text Color */
.login-button a:hover {
    color: #fff;
    background-color: #881b12;
    text-decoration: none;
}

/* Hover Option 2: Change Background Color */
.login-button a:hover {
    background-color: #6b140d; /* A darker shade */
    color: #fff;
    text-decoration: none;
}
    



</style>
<!-- Start Header top Area -->
<div class="header_top">
    <div class="container">
        <div class="row">
            <div class="col-xs-12 col-md-5 col-sm-6">
                <p>
                    <span><i class="fa fa-user"></i>bbbccc@gmail.com</span>
                    <span><i class="fa fa-phone"></i>0404902044</span>

                </p>
            </div>

            <div class="col-xs-12 col-md-3 col-sm-6">
            </div>
            <div class="col-xs-12 col-md-4 col-sm-12">
                <ul class="social-icons">
                    <li><a class="facebook" href="#"><i class="fa fa-facebook"></i></a></li>
                    <li><a class="twitter" href="#"><i class="fa fa-twitter"></i></a></li>
                    <li><a class="rss" href="#"><i class="fa fa-rss"></i></a></li>
                    <li><a class="google" href="#"><i class="fa fa-google-plus"></i></a></li>
                    <li><a class="pinterest" href="#"><i class="fa fa-pinterest"></i></a></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!--Start nav  area -->
<div class="nav_areas hidden-xs hidden-sm">
    <div class="nav_area">
        <div class="container">
            <div class="row">
                <!--logo area-->
                <div class="col-md-3 col-sm-4 col-xs-5">
                    <div class="logo">
                        <a href="index.html"><img src="bbccassests/img/logo/logo5.jpg" alt="" /></a>
                        <h3>Bhutanese Centre Canberra</h3>
                    </div>
                </div>
                <!--end logo area-->
                <!--nav area-->
                <div class="col-md-9 col-sm-8 col-xs-8">
                    <!--  nav menu-->
                    <nav class="menu">
                        <ul class="navid">
                            <li><a href="index.php">Home</a></li>
                            <li><a href="about-us.php">About</a></li>
                            <li><a href="menuItem.php">Service</a></li>
                          
                            <li><a href="">Team</a></li>
                            <li><a href="blog.html">Blogs</a></li>

                            <li><a href="contact-us.php">Contact</a></li>
                            <li>
                                <a href="login.php">
                                    <button class="btn btn-sm btn-danger">Login</button>
                                </a>
                            </li>                        </ul>
                                                </nav>
                    <!--end  nav menu-->
                </div>
            </div>
        </div>
    </div>

</div>
<!--end nav area-->'
?>



<!---->
<?php
//echo '
//<header>
//            <div class="main-nav">
//               <!--<div class="logo">
//                   <img src="assets/images/logo/logo1.png">
//               </div>
//               -->
//
//               <h1 class="fw-bold text-primary m-0" style="font-family: Tahoma, Geneva, Verdana, sans-serif;">
//        Tobgay <span class="text-dark">TechStrat</span>
//    </h1>
//
//               <div class="menu-toggle"></div>
//               <div class="menu">
//                   <ul>
//                       <li><a href="index.php">Home</a></li>
//                       <li><a href="about-us.php">About</a></li>
//                       <li><a href="menuItem.php">Services</a></li>
//<!--
//                       <li><a href="order.php">Regist</a></li>
//-->
//                       <li><a href="contact-us.php">Contact</a></li>
//                   </ul>
//               </div>
//
//                <div class="book">
//                    <ul>
//                        <li><a href="login.php">Admin Login</a></li>
//                    </ul>
//                </div>
//            </div>
//   </header>'
//?>
