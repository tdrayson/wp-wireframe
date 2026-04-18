/**
 * SettingsPage — top-level layout component.
 *
 * Uses the @wordpress/admin-ui Page component for the header
 * with title + actions + tabs, then section cards below.
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { Button, SnackbarList } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Page } from '@wordpress/admin-ui';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';
import { useSettings } from '../hooks/useSettings';
import { getTabs, getSections } from '../utils/mapConfig';
import SettingsSection from './SettingsSection';

export default function SettingsPage() {
  const { config, isDirty, saving, save, reset, hasSaved } = useSettings();
  const notices = useSelect(
    (select) =>
      select(noticesStore)
        .getNotices()
        .filter((n) => n.type === 'snackbar'),
    [],
  );
  const { removeNotice } = useDispatch(noticesStore);
  const tabs = getTabs(config);
  const [activeTab, setActiveTab] = useState(tabs[0]?.id || '');
  const [confirmReset, setConfirmReset] = useState(false);

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

  const headerActions = (
    <>
      {hasSaved &&
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
      <Button
        size="compact"
        variant="primary"
        onClick={save}
        disabled={saving || !isDirty}
        isBusy={saving}
      >
        {saving ? __('Saving…', 'wp-wireframe') : __('Save', 'wp-wireframe')}
      </Button>
    </>
  );

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
                  onClick={() => setActiveTab(tab.id)}
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
