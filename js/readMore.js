/**
 * Hide all additional information content by default and attach events to the read more links to toggle display
 */
function initReadMoreToggles() {
	$('.additionalInfo').hide();
	$('button[data-type=readMoreButton],a[data-type=readMoreButton]').click(function (e) {
		e.preventDefault();
		var targetId = '#' + $(this).data('context');
		var target = $(targetId);
		var button = $(this);
		if (target.data("isOpen")) {
			target.data("isOpen", false);
			$(targetId + ' > .additionalInfo').fadeOut(250);
			button.text(button.text().replace("Read less", "Read more"));
		} else {
			target.data("isOpen", true);
			$(targetId + ' > .additionalInfo').fadeIn(250);
			button.text(button.text().replace("Read more", "Read less"));
		}
		target.focus();
	});
}
$(document).ready(function(){
	initReadMoreToggles();
});