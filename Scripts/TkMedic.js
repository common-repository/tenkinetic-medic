jQuery(function($)
{
	$('.dynatable').dynatable(
	{
		features: {
			paginate: true,
			search: true,
			recordCount: true,
			perPageSelect: false,
			pushState: false,
			copyHeaderClass: true
		}
	});
	$('.dynatable').each(function()
	{
		var dynatable = $(this).data('dynatable');
		dynatable.paginationPerPage.set(25);
		dynatable.process();
	});
});