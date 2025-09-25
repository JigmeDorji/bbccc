(function($) {
    "use strict";

    /*========================
      MOBILE NAV MENU ACTIVE JS
      ========================*/
    $('.mobile-menu nav').meanmenu({
        meanScreenWidth: "990",
        meanMenuContainer: ".mobile-menu",
    });

    /*=====================
      MAIN SLIDER ACTIVE JS
      =======================*/
    $('[data-toggle="tooltip"]').tooltip();

    /*=====================
      MAIN SLIDER ACTIVE JS
      =======================*/
    $('#mainSlider').nivoSlider({
        directionNav: false,
        animSpeed: 500,
        slices: 18,
        pauseTime: 100000,
        pauseOnHover: false,
        controlNav: true,
        prevText: '<i class="fa fa-angle-left nivo-prev-icon"></i>',
        nextText: '<i class="fa fa-angle-right nivo-next-icon"></i>'
    });

    /*=====================
      TOP FIXED NAV ACTIVE JS
      =======================*/
    $('.nav_areas').scrollToFixed({
        preFixed: function() {
            $(this).find('.nav_area').addClass('prefix');
        },
        postFixed: function() {
            $(this).find('.nav_area').addClass('postfix').removeClass('prefix');
        }
    });

    /*=====================
     ONE PAGE NAV ACTIVE JS
    =======================*/
    $('.navid').onePageNav({
        currentClass: 'current',
        changeHash: false,
        scrollSpeed: 1000,
        scrollThreshold: 0.5,
        filter: '',
        easing: 'swing',
    });

    /*=====================
    SMOOTH SCROLL ACTIVE JS
    =======================*/
    $('.menu ul li a').on('click', function(e) {
        e.preventDefault();
        var link = this;
        $.smoothScroll({
            offset: -80,
            scrollTarget: link.hash
        });
    });

    $(".testi_curosel").owlCarousel({
        autoPlay: false,
        slideSpeed: 2000,
        pagination: true,
        navigation: false,
        items: 1,
        transitionStyle: "backSlide",
        /* [This code for animation ] */
        navigationText: ["<i class='fa fa-angle-left'></i>", "<i class='fa fa-angle-right'></i>"],
        itemsDesktop: [1199, 1],
        itemsDesktopSmall: [980, 1],
        itemsTablet: [768, 1],
        itemsMobile: [479, 1],
    });



    /*=================
    COUNTERUP ACTIVE JS
    ===================*/
    $('.counterup').counterUp({
        delay: 10,
        time: 1000
    });

    /*=====================
    IMAGE LOADED  ACTIVE JS
    =======================*/
    $('.prot_image_load').imagesLoaded(function() {

        if ($.fn.isotope) {
            var $portfolio = $('.gallery_items');
            $portfolio.isotope({
                itemSelector: '.grid-item',
                filter: '*',
                resizesContainer: true,
                layoutMode: 'masonry',
            });
            var portactive = $('.filter-menu li');
            portactive.on('click', function() {
                portactive.removeClass('current_menu_item');
                $(this).addClass('current_menu_item');
                var selector = $(this).attr('data-filter');
                $portfolio.isotope({
                    filter: selector,
                });
            });
        };

    });

    /*=================                     
    SCOLLUP ACTIVE JS
    ===================*/
    $.scrollUp({
        scrollName: 'scrollUp', // Element ID
        scrollDistance: 300, // Distance from top/bottom before showing element (px)
        scrollFrom: 'top', // 'top' or 'bottom'
        scrollSpeed: 2000, // Speed back to top (ms)
        easingType: 'linear',
        animation: 'fade', // Fade, slide, none
        animationSpeed: 300, // Animation speed (ms)
        scrollText: '<i class="fa fa-angle-up"></i>', // Text for element, can contain HTML
        zIndex: 2147483647 // Z-Index for the overlay
    });

    /*===========================
    ABOUT VIDEO VENOBOX ACTIVE JS
    ============================*/
    $('.venobox').venobox({
        framewidth: '700px', // default: ''
        frameheight: '450px', // default: ''
        border: '2px', // default: '0'
        bgcolor: '#ddd', // default: '#fff'
        titleattr: 'data-title', // default: 'title'
        numeratio: true, // default: false
        infinigall: true // default: false
    });

    new WOW().init();



})(jQuery);