
$(document).ready(function(){
  $('.menu-toggle').click(function(){
    $('.menu-toggle').toggleClass('active')
    $('.menu').toggleClass('active')
  });
});

// ---------------------------------------

$('.cal-my').each(function () {
    $(this).prop('Counter',0).animate({
        Counter: $(this).text()
    }, {
        duration: 5000,
        easing: 'swing',
        step: function (now) {
            $(this).text(Math.ceil(now));
        }
    });
});

// ---------------------------------------