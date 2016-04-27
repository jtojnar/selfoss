selfoss.events = {

    /* last hash before hash change */
    lasthash: "",

    /**
     * init events when page loads first time
     */
    init: function() {
        selfoss.events.navigation();
        selfoss.events.entries();
        selfoss.events.search();

        // re-init on media query change
        if ((typeof window.matchMedia) != "undefined") {
            var mq = window.matchMedia("(min-width: 641px) and (max-width: 1024px)");
            if ((typeof mq.addListener) != "undefined")
                mq.addListener(selfoss.events.entries);
        }

        // window resize
        $("#nav-tags-wrapper").mCustomScrollbar({
            advanced:{
                updateOnContentResize: true
            }
        });
        $(window).bind("resize", selfoss.events.resize);
        selfoss.events.resize();
        selfoss.events.updateUnreadBelowTheFold();

        // hash change event
        window.onpopstate = selfoss.events.popState;

        selfoss.events.popState();
    },
    
    
    /**
     * handle History change
     */
    popState: function(e) {
        var newLocation = document.location.toString().replace(new RegExp('^' + $('base').attr('href')), '');
        if (newLocation == 'settings/sources') {
            selfoss.showSettings();
        } else if ((newLocation = newLocation.replace(/^tag\/(.+)/, '$1')) !== '') {
            selfoss.showTag(newLocation);
        } else if ((newLocation = newLocation.replace(/^source\/(.+)/, '$1')) !== '') {
            selfoss.showSource(newLocation);
        }
    },
    
    
    /**
     * set automatically the height of the tags and set scrollbar for div scrolling
     */
    resize: function() {
        // only set height if smartphone is false
        if(selfoss.isSmartphone()==false) {
            var start = $('#nav-tags-wrapper').position().top;
            var windowHeight = $(window).height();
            $('#nav-tags-wrapper').height(windowHeight - start - 100);
            $('#nav').show();
        } else {
            $('#nav-tags-wrapper').height("auto");
            $("#nav-tags-wrapper").mCustomScrollbar("disable",selfoss.isSmartphone());
        }
        if ($('#floating-unread').is(':visible')) {
            selfoss.events.updateUnreadBelowTheFold();
        }
    },


    /**
     * updates the "unread below the fold" count
     */
    updateUnreadBelowTheFold: function() {
        if (!selfoss.isTablet()) {
            var $floatingUnread = $('#floating-unread');
            if ($floatingUnread.length) {
                var unreadStats = selfoss.events.countUnreadBelowTheFold();

                selfoss.refreshUnreadBelowTheFold(unreadStats);
            }
        }
    },


    /**
     * counts the number of unread entries below the fold
     *
     * @return int number of unread entries below the fold, null when unknown
     */
    countUnreadBelowTheFold: function() {
        var foldPos = $(window).scrollTop() + $(window).height();
        var contentBottom = $('#content').outerHeight() + $('#content').offset().top;

        var unreadStats = selfoss.events.countCurrentUnread();

        $starredFilter = $('#nav-filter-starred');
        $searchTerms = $('#search-list li');
        if ($starredFilter.hasClass('active') || $searchTerms.length > 0) {
            // disabled for starred filter and when search is active
            // since we don't known the number of unread entries in the current view
            if (contentBottom <= foldPos) {
                // everything is visible, no unread entries below the fold
                unreadStats = 0;
            } else {
                // not everything is visible, unread count is unknown
                unreadStats = null;
            }
        } else {
            $('#content .entry.unread').each(function() {
                var entryBottomPos = $(this).offset().top + $(this).outerHeight();
                if (entryBottomPos < foldPos) {
                    unreadStats--;
                } else {
                    // we reached the bottom of the visible window, no need to go further
                    return false;
                }
            });
        }

        return unreadStats;
    },


    /**
     * counts the number of unread entries in the current view
     *
     * @return int number of unread entries in the current view
     */
    countCurrentUnread: function() {
        // the result might already be computed
        var $currentUnreadCount = $('#current-unread-count');
        if ($currentUnreadCount.length > 0) {
            return $currentUnreadCount.data('unreadCount');
        }

        var unreadStats = parseInt($('.nav-filter-unread span').html()); // unread total
        var $selectedSource = $('#nav-tags-wrapper li.active'); // selected tag/source
        var unreadSource = parseInt($selectedSource.find('span.unread').html()); // unread tag/source
        if (!$selectedSource.hasClass('nav-tags-all')) {
            // using the unread count from the tag/source except for "all tags"
            unreadStats = unreadSource > 0 ? unreadSource : 0;
        }

        // saving the result in the current view, to avoid most of the work next time
        $currentUnreadCount = $('<div id="current-unread-count"></div>');
        $currentUnreadCount.hide();
        $currentUnreadCount.data('unreadCount', unreadStats);
        $('#content').prepend($currentUnreadCount);

        return unreadStats;
    }
};
