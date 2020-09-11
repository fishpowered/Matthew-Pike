/**
 * Hide all additional information content by default and attach events to the read more links to toggle display
 */
function initReadMoreToggles() {
	$('.additionalInfo').hide();
	$('button[data-type=readMoreButton],a[data-type=readMoreButton]').click(function (e) {
		// TODO Add aria accessibility attributes!
		e.preventDefault();
		var targetId = '#' + $(this).data('context');
		var target = $(targetId);
		var button = $(this);
		if (target.data("isOpen")) {
			target.data("isOpen", false);
			if($(this).data("searchchildren")){
				$(targetId).find('.additionalInfo').fadeOut(250);
			}else{
				$(targetId + ' > .additionalInfo').fadeOut(250);
			}
			button.text(button.text().replace("less", "more"));
		} else {
			target.data("isOpen", true);
			if($(this).data("searchchildren")) {
				$(targetId).find('.additionalInfo').fadeIn(250);
			}else{
				$(targetId + ' > .additionalInfo').fadeIn(250);
			}
			button.text(button.text().replace("more", "less"));
		}
		target.focus();
	});
}
$(document).ready(function(){
	initReadMoreToggles();
});