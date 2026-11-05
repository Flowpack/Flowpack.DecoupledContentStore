/**
 * Warns editors in the content module while automatic content releases are paused, because publishing still works
 * then - the change just does not reach the live site until somebody resumes the releases.
 *
 * Plain ES5, served as it is: this file is not part of the package's esbuild bundle, so it can be registered under
 * Neos.Neos.Ui.resources.javascript without a build step.
 */
(function () {
    'use strict';

    const DATA_SOURCE_IDENTIFIER = 'flowpack-decoupledcontentstore-automatic-release-status';
    const POLL_INTERVAL_IN_MS = 30000;
    const ELEMENT_ID = 'flowpack-decoupledcontentstore-release-warning';
    const VISIBLE_CLASS = 'flowpack-decoupledcontentstore-release-warning-visible';
    const HEIGHT_PROPERTY = '--flowpack-decoupledcontentstore-release-warning-height';

    let routes = null;
    let warned = false;

    // the UI inlines its route table as JSON into <script id="initialData">, which only exists once the head is parsed
    function readRoutes() {
        const initialData = document.getElementById('initialData');

        try {
            return initialData ? JSON.parse(initialData.textContent).routes : null;
        } catch (error) {
            return null;
        }
    }

    function statusUri() {
        const dataSourceUri = routes && routes.core && routes.core.service && routes.core.service.dataSource;

        return dataSourceUri ? dataSourceUri + '/' + DATA_SOURCE_IDENTIFIER : null;
    }

    // once, not on every poll: an editor cannot act on this, but without it a broken endpoint looks exactly like
    // "no releases are paused"
    function warn(reason) {
        if (!warned) {
            warned = true;
            console.warn('[Flowpack.DecoupledContentStore] cannot read the automatic release status: ' + reason);
        }
    }

    // The warning sits in the document flow above the Neos chrome, which is positioned fixed and would otherwise
    // ignore it. Publishing its height lets the stylesheet shrink the application container by exactly that much.
    function publishHeight() {
        const element = document.getElementById(ELEMENT_ID);

        if (element) {
            document.documentElement.style.setProperty(HEIGHT_PROPERTY, element.offsetHeight + 'px');
        }
    }

    function render(status) {
        let element = document.getElementById(ELEMENT_ID);

        if (!status.paused) {
            if (element) {
                element.parentNode.removeChild(element);
                document.documentElement.classList.remove(VISIBLE_CLASS);
                document.documentElement.style.removeProperty(HEIGHT_PROPERTY);
            }
            return;
        }

        if (!element) {
            element = document.createElement('div');
            element.id = ELEMENT_ID;
            element.setAttribute('role', 'alert');
            document.body.insertBefore(element, document.body.firstChild);
            document.documentElement.classList.add(VISIBLE_CLASS);
        }

        element.textContent = status.message;
        publishHeight();
    }

    function poll() {
        const uri = statusUri();
        if (!uri) {
            warn('routes.core.service.dataSource is missing in #initialData');
            return;
        }

        fetch(uri, {credentials: 'same-origin'})
            .then(function (response) {
                if (!response.ok) {
                    warn(uri + ' answered ' + response.status);
                    return null;
                }
                return response.json();
            })
            .then(function (status) {
                if (status) {
                    render(status);
                }
            })
            .catch(function (error) {
                warn(String(error));
            });
    }

    function start() {
        routes = readRoutes();
        poll();
        window.setInterval(poll, POLL_INTERVAL_IN_MS);
        // the message wraps to a different number of lines as the window gets narrower
        window.addEventListener('resize', publishHeight);
    }

    // running before the UI host means running before <body> exists
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
