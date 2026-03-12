<?php
echo '
<!-- Footer -->
<footer class="bbcc-footer">
    <div class="bbcc-container">
        <div class="bbcc-footer__grid">

            <!-- Brand Column -->
            <div class="bbcc-footer__brand">
                <h3>Bhutanese Buddhist &amp; Cultural Centre</h3>
                <p>BBCC provides spiritual and pastoral services, cultural programs, and community engagement activities for Bhutanese residents in Canberra and nearby regions.</p>
                <div class="bbcc-footer__social">
                    <a href="https://www.facebook.com/profile.php?id=100084018901076" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                    <a href="#" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a>
                    <a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="bbcc-footer__col">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="index.php">Home</a></li>
                    <li><a href="about-us.php">About Us</a></li>
                    <li><a href="services.php">Services</a></li>
                    <li><a href="events.php">Events</a></li>
                </ul>
            </div>

            <!-- Programs -->
            <div class="bbcc-footer__col">
                <h4>Programs</h4>
                <ul>
                    <li><a href="services.php">Spiritual Services</a></li>
                    <li><a href="services.php">Dzongkha Classes</a></li>
                    <li><a href="events.php">Community Events</a></li>
                    <li><a href="parentAccountSetup.php">Register</a></li>
                </ul>
            </div>

            <!-- Contact Info -->
            <div class="bbcc-footer__col">
                <h4>Contact</h4>
                <ul>
                    <li><i class="fa-solid fa-envelope" style="color:#c9a84c;margin-right:6px;font-size:.7rem;"></i> bbbccc@gmail.com</li>
                    <li><i class="fa-solid fa-phone" style="color:#c9a84c;margin-right:6px;font-size:.7rem;"></i> 0404 902 044</li>
                    <li><i class="fa-solid fa-location-dot" style="color:#c9a84c;margin-right:6px;font-size:.7rem;"></i> Canberra, ACT, Australia</li>
                </ul>
            </div>

        </div>

        <div class="bbcc-footer__bottom">
            <p>&copy; ' . date('Y') . ' Bhutanese Buddhist &amp; Cultural Centre (BBCC). All rights reserved.</p>
            <p class="bbcc-footer__credit">Designed &amp; Developed by
                <span class="dev-card-wrap">
                    <a href="https://www.linkedin.com/in/jigme-dorji-b18405200" target="_blank" rel="noopener noreferrer" class="dev-card-trigger">Jigme Dorji</a>
                    <span class="dev-card" aria-hidden="true">
                        <span class="dev-card__accent"></span>
                        <span class="dev-card__header">
                            <span class="dev-card__avatar"><i class="fa-solid fa-laptop-code"></i></span>
                            <span class="dev-card__info">
                                <strong>JD &amp; T Digital Solutions</strong>
                                <em>Web &middot; Software &middot; Mobile</em>
                            </span>
                        </span>
                        <span class="dev-card__services">
                            <span class="dev-card__badge"><i class="fa-solid fa-globe"></i> Web Design</span>
                            <span class="dev-card__badge"><i class="fa-solid fa-code"></i> Software Dev</span>
                            <span class="dev-card__badge"><i class="fa-solid fa-mobile-screen-button"></i> Mobile Apps</span>
                        </span>
                        <span class="dev-card__tagline">Cutting-edge web &amp; app solutions at surprisingly reasonable prices. Contact us today!</span>
                        <span class="dev-card__contact">
                            <span><i class="fa-solid fa-envelope"></i> dorjijigme32@gmail.com</span>
                            <span><i class="fa-solid fa-phone"></i> 0404 902 044</span>
                        </span>
                    </span>
                </span>
                &amp;
                <span class="dev-card-wrap">
                    <a href="https://www.linkedin.com/in/tshering-tshering/" target="_blank" rel="noopener noreferrer" class="dev-card-trigger">Tshering</a>
                    <span class="dev-card" aria-hidden="true">
                        <span class="dev-card__accent"></span>
                        <span class="dev-card__header">
                            <span class="dev-card__avatar"><i class="fa-solid fa-laptop-code"></i></span>
                            <span class="dev-card__info">
                                <strong>JD &amp; T Digital Solutions</strong>
                                <em>Web &middot; Software &middot; Mobile</em>
                            </span>
                        </span>
                        <span class="dev-card__services">
                            <span class="dev-card__badge"><i class="fa-solid fa-globe"></i> Web Design</span>
                            <span class="dev-card__badge"><i class="fa-solid fa-code"></i> Software Dev</span>
                            <span class="dev-card__badge"><i class="fa-solid fa-mobile-screen-button"></i> Mobile Apps</span>
                        </span>
                        <span class="dev-card__tagline">Cutting-edge web &amp; app solutions at surprisingly reasonable prices. Contact us today!</span>
                        <span class="dev-card__contact">
                            <span><i class="fa-solid fa-envelope"></i> dorjijigme32@gmail.com</span>
                            <span><i class="fa-solid fa-phone"></i> 0404 902 044</span>
                        </span>
                    </span>
                </span>
            </p>
        </div>
    </div>
</footer>

<!-- Scroll to Top -->
<button class="bbcc-scrolltop" id="bbccScrollTop" aria-label="Scroll to top">
    <i class="fa-solid fa-arrow-up"></i>
</button>

<script>
(function() {
    var btn = document.getElementById("bbccScrollTop");
    window.addEventListener("scroll", function() {
        btn.classList.toggle("show", window.scrollY > 400);
    });
    btn.addEventListener("click", function() {
        window.scrollTo({ top: 0, behavior: "smooth" });
    });
})();
</script>
';
?>
