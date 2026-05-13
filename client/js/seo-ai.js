(function ($) {
    $('.cms [id$="_SEO"]').entwine({
        onmatch: function () {
            this._super();

            if (new URLSearchParams(window.location.search).get('openSeo') !== '1') {
                return;
            }

            var $seo = this;

            setTimeout(function () {
                if ($seo.hasClass('ui-accordion') && typeof $seo.accordion === 'function') {
                    $seo.accordion('option', 'active', 0);

                    var url = new URL(window.location.href);
                    url.searchParams.delete('openSeo');
                    window.history.replaceState({}, document.title, url.toString());
                }
            }, 300);
        }
    });
})(jQuery);