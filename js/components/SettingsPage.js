/**
 * SettingsPage — top-level layout component.
 *
 * Uses the @wordpress/admin-ui Page component for the header
 * with title + actions + tabs, then section cards below.
 */
import { useState, useEffect, useRef, useCallback, useMemo } from '@wordpress/element';
import { Button, SnackbarList } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Page } from '@wordpress/admin-ui';
import { chevronLeft } from '@wordpress/icons';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { useSettings } from '../hooks/useSettings';
import { isTabVisible } from '../utils/conditions';
import { getTabs, getSections } from '../utils/mapConfig';
import {
  findActiveDetailField,
  writeEntryParam,
  URL_CHANGE_EVENT,
} from '../utils/tableDetailUrl';
import SettingsSection from './SettingsSection';
import TableDetailView from './fields/TableDetailView';

/**
 * Pick the active tab on first render: prefer ?tab= from the URL when it
 * matches a real tab, otherwise fall back to the first declared tab.
 */
function resolveInitialTab(validIds) {
  if (typeof window === 'undefined') {
    return validIds[0] || '';
  }
  const fromUrl = new URLSearchParams(window.location.search).get('tab');
  if (fromUrl && validIds.includes(fromUrl)) {
    return fromUrl;
  }
  return validIds[0] || '';
}

/**
 * Write the active tab id to ?tab= using replaceState so we don't pollute
 * the browser history with a new entry on every tab click.
 */
function writeTabToUrl(tabId) {
  if (typeof window === 'undefined' || !tabId) {
    return;
  }
  const url = new URL(window.location.href);
  if (url.searchParams.get('tab') === tabId) {
    return;
  }
  url.searchParams.set('tab', tabId);
  window.history.replaceState(null, '', url.toString());
}

export default function SettingsPage() {
  const { config, values, isDirty, saving, save, reset, hasSaved, canSave, canReset, restBase } = useSettings();
  const notices = useSelect(
    (select) =>
      select(noticesStore)
        .getNotices()
        .filter((n) => n.type === 'snackbar'),
    [],
  );
  const { removeNotice } = useDispatch(noticesStore);
  // Filter tabs by their `conditions` against current values. Sections and
  // fields already do this — extending to tabs lets authors gate a whole
  // tab behind a toggle (e.g. show "Advanced" only when advanced_mode=true).
  const allTabs = getTabs(config);
  const tabs = useMemo(
    () => allTabs.filter((tab) => isTabVisible(tab, values)),
    [allTabs, values],
  );
  const tabIds = useMemo(() => tabs.map((t) => t.id), [tabs]);
  const [activeTab, setActiveTab] = useState(() =>
    resolveInitialTab(tabIds),
  );
  const [confirmReset, setConfirmReset] = useState(false);

  // Persist the active tab as `?tab=...` so reloads + back/forward keep state.
  const updateTab = useCallback(
    (tabId) => {
      setActiveTab(tabId);
      writeTabToUrl(tabId);
    },
    [],
  );

  // If the user navigates with browser back/forward, sync the active tab.
  useEffect(() => {
    const handler = () => {
      const next = resolveInitialTab(tabIds);
      setActiveTab(next);
    };
    window.addEventListener('popstate', handler);
    return () => window.removeEventListener('popstate', handler);
  }, [tabIds]);

  // If the URL referenced an unknown tab on first load, clean it up so the
  // first valid tab is reflected in the address bar.
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    if (params.get('tab') !== activeTab) {
      writeTabToUrl(activeTab);
    }
    // Run only once on mount — we just want to reconcile the initial URL.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Detail mode: any URL like ?entry-{fieldId}=... swaps the entire page
  // into a chrome-less single-entry view. Re-evaluate on browser back/forward
  // and on our own URL writes (which dispatch URL_CHANGE_EVENT).
  const [detailMode, setDetailMode] = useState(() =>
    findActiveDetailField(config),
  );

  useEffect(() => {
    const handler = () => setDetailMode(findActiveDetailField(config));
    window.addEventListener('popstate', handler);
    window.addEventListener(URL_CHANGE_EVENT, handler);
    return () => {
      window.removeEventListener('popstate', handler);
      window.removeEventListener(URL_CHANGE_EVENT, handler);
    };
  }, [config]);

  const currentTab = tabs.find((t) => t.id === activeTab);
  const sections = currentTab ? getSections(currentTab) : [];
  const pageRef = useRef(null);

  // Measure the Page header height and set a CSS variable so tabs
  // can sticky-position directly below it.
  useEffect(() => {
    const el = pageRef.current;
    if (!el) return;

    const header = el.querySelector('.admin-ui-page__header');
    if (!header) return;

    const update = () => {
      el.style.setProperty(
        '--wireframe-header-height',
        `${header.offsetHeight}px`,
      );
    };

    update();
    const observer = new ResizeObserver(update);
    observer.observe(header);

    return () => observer.disconnect();
  }, []);

  const handleReset = () => {
    if (!confirmReset) {
      setConfirmReset(true);
      return;
    }
    setConfirmReset(false);
    reset();
  };

  // Server tells us whether this user is allowed to save or reset. When
  // false, the relevant button is hidden entirely (rather than disabled)
  // so read-only users see no action affordance at all.
  const headerActions = (
    <>
      {canReset &&
        hasSaved &&
        (confirmReset ? (
          <>
            <Button
              size="compact"
              variant="secondary"
              onClick={() => setConfirmReset(false)}
              disabled={saving}
            >
              {__('Cancel', 'wp-wireframe')}
            </Button>
            <Button
              size="compact"
              variant="secondary"
              isDestructive
              onClick={handleReset}
              disabled={saving}
            >
              {__('Confirm Reset', 'wp-wireframe')}
            </Button>
          </>
        ) : (
          <Button
            size="compact"
            variant="secondary"
            onClick={handleReset}
            disabled={saving}
          >
            {__('Reset', 'wp-wireframe')}
          </Button>
        ))}
      {canSave && (
        <Button
          size="compact"
          variant="primary"
          onClick={save}
          disabled={saving || !isDirty}
          isBusy={saving}
        >
          {saving ? __('Saving…', 'wp-wireframe') : __('Save', 'wp-wireframe')}
        </Button>
      )}
    </>
  );

  // Detail view — keep the Page header but drop the tabs nav, section
  // cards, and Save/Reset actions (the detail view is stateless).
  if (detailMode) {
    const { field, entryId } = detailMode;
    const detailConfig = field.args.detail_view || {};
    const detailActions = (field.args.actions || []).filter(
      (a) => a.show_in_detail && !a.opens_detail,
    );
    const restNs = restBase.replace(/\/settings\/.*$/, '');
    const pageId = restBase.split('/settings/')[1] || 'default';
    const tableBase = `${restNs}/table/${pageId}/${field.id}`;

    const exitDetail = () => writeEntryParam(field.id, '');

    return (
      <div className="wireframe-page wireframe-page--detail" ref={pageRef}>
        <Page
          title={config.title || __('Settings', 'wp-wireframe')}
          subTitle={config.subtitle || undefined}
        >
          {/* Sub-header band (where tabs would normally sit) hosts the
              Back affordance so detail mode mirrors the regular page chrome. */}
          <div className="wireframe-page__tabs-container">
            <div className="wireframe-page__detail-subheader">
              <Button
                variant="link"
                icon={chevronLeft}
                iconSize={16}
                onClick={exitDetail}
                className="wireframe-page__back-link"
              >
                {detailConfig.back_label ||
                  __('Back to all entries', 'wp-wireframe')}
              </Button>
            </div>
          </div>

          <div className="wireframe-page__content">
            <TableDetailView
              entryId={entryId}
              tableBase={tableBase}
              defaultTitle={
                typeof detailConfig.title === 'string' ? detailConfig.title : ''
              }
              actions={detailActions}
              onEntryRemoved={exitDetail}
            />
          </div>
        </Page>
        <SnackbarList
          notices={notices}
          className="components-notices__snackbar"
          onRemove={removeNotice}
        />
      </div>
    );
  }

  return (
    <div className="wireframe-page" ref={pageRef}>
      <Page
        title={config.title || __('Settings', 'wp-wireframe')}
        subTitle={config.subtitle || undefined}
        actions={headerActions}
      >
        {/* Tabs */}
        {tabs.length > 1 && (
          <div className="wireframe-page__tabs-container">
            <nav className="wireframe-page__tabs" role="tablist">
              {tabs.map((tab) => (
                <button
                  key={tab.id}
                  role="tab"
                  aria-selected={tab.id === activeTab}
                  className={`wireframe-page__tab ${
                    tab.id === activeTab ? 'is-active' : ''
                  }`}
                  onClick={() => updateTab(tab.id)}
                >
                  {tab.title}
                </button>
              ))}
            </nav>
          </div>
        )}

        {/* Section cards */}
        <div className="wireframe-page__content">
          {sections.map((section) => (
            <SettingsSection key={section.id} section={section} />
          ))}
        </div>
      </Page>
      <SnackbarList
        notices={notices}
        className="components-notices__snackbar"
        onRemove={removeNotice}
      />
    </div>
  );
}
