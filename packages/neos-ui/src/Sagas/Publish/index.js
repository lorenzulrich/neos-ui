import {takeEvery, put, call, select} from 'redux-saga/effects';
import {$get} from 'plow-js';

import {actionTypes, actions, selectors} from '@neos-project/neos-ui-redux-store';
import backend from '@neos-project/neos-ui-backend-connector';
import {getGuestFrameDocument} from '@neos-project/neos-ui-guest-frame/src/dom';

const {publishableNodesInDocumentSelector} = selectors.CR.Workspaces;

export function * watchPublish() {
    const {q} = backend.get();
    const {publish} = backend.get().endpoints;

    yield takeEvery(actionTypes.CR.Workspaces.PUBLISH, function * publishNodes(action) {
        const {nodeContextPaths, targetWorkspaceName} = action.payload;

        if (nodeContextPaths.count() > 0) {
            yield put(actions.UI.Remote.startPublishing());

            try {
                const feedback = yield call(publish, nodeContextPaths, targetWorkspaceName);
                yield put(actions.UI.Remote.finishPublishing());
                yield put(actions.ServerFeedback.handleServerFeedback(feedback));

                //
                // Fetch the current content canvas document node, to retrieve a new preview uri,
                // but only if the document is amongst the latest published nodes
                //
                const contentCanvasContextPath = yield select($get('ui.contentCanvas.contextPath'));

                if (nodeContextPaths.contains(contentCanvasContextPath)) {
                    const [contentCanvasNode] = yield q(contentCanvasContextPath).get();
                    const previewUrl = $get('previewUri', contentCanvasNode);

                    yield put(actions.UI.ContentCanvas.setPreviewUrl(previewUrl));
                }
            } catch (error) {
                console.error('Failed to publish', error);
            }
        }
    });
}

export function * watchToggleAutoPublish() {
    yield takeEvery(actionTypes.User.Settings.TOGGLE_AUTO_PUBLISHING, function * publishInitially() {
        const state = yield select();
        const isAutoPublishingEnabled = $get('user.settings.isAutoPublishingEnabled', state);

        if (isAutoPublishingEnabled) {
            const publishableNodesInDocument = publishableNodesInDocumentSelector(state);
            yield put(actions.CR.Workspaces.publish(publishableNodesInDocument.map($get('contextPath')), 'live'));
        }
    });
}

export function * watchChangeBaseWorkspace() {
    const {changeBaseWorkspace} = backend.get().endpoints;
    yield takeEvery(actionTypes.CR.Workspaces.CHANGE_BASE_WORKSPACE, function * change(action) {
        try {
            const feedback = yield call(changeBaseWorkspace, action.payload);
            yield put(actions.ServerFeedback.handleServerFeedback(feedback));
        } catch (error) {
            console.error('Failed to change base workspace', error);
        }
    });
}

export function * watchDiscard() {
    const {discard} = backend.get().endpoints;

    yield takeEvery(actionTypes.CR.Workspaces.DISCARD, function * discardNodes(action) {
        yield put(actions.UI.Remote.startDiscarding());

        try {
            const currentContentCanvasContextPath = yield select(selectors.UI.ContentCanvas.getCurrentContentCanvasContextPath);
            const feedback = yield call(discard, action.payload);
            yield put(actions.UI.Remote.finishDiscarding());
            yield put(actions.ServerFeedback.handleServerFeedback(feedback));

            // check if the currently focused document node has been removed
            const contentCanvasNodeIsStillThere = Boolean(yield select(selectors.CR.Nodes.byContextPathSelector(currentContentCanvasContextPath)));

            // if not, reload the document
            if (contentCanvasNodeIsStillThere) {
                getGuestFrameDocument().location.reload();
            }

            // reload the page tree
            yield put(actions.UI.PageTree.reloadTree());
        } catch (error) {
            console.error('Failed to discard', error);
        }
    });
}
