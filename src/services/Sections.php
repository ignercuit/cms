<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\services;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\elements\Entry;
use craft\errors\EntryTypeNotFoundException;
use craft\errors\SectionNotFoundException;
use craft\events\ConfigEvent;
use craft\events\EntryTypeEvent;
use craft\events\SectionEvent;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\helpers\StringHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Structure;
use craft\queue\jobs\ResaveElements;
use craft\records\EntryType as EntryTypeRecord;
use craft\records\Section as SectionRecord;
use craft\records\Section_SiteSettings as Section_SiteSettingsRecord;
use yii\base\Component;
use yii\base\Exception;

/**
 * Sections service.
 * An instance of the Sections service is globally accessible in Craft via [[\craft\base\ApplicationTrait::getSections()|`Craft::$app->sections`]].
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Sections extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event SectionEvent The event that is triggered before a section is saved.
     */
    const EVENT_BEFORE_SAVE_SECTION = 'beforeSaveSection';

    /**
     * @event SectionEvent The event that is triggered after a section is saved.
     */
    const EVENT_AFTER_SAVE_SECTION = 'afterSaveSection';

    /**
     * @event SectionEvent The event that is triggered before a section is deleted.
     */
    const EVENT_BEFORE_DELETE_SECTION = 'beforeDeleteSection';

    /**
     * @event SectionEvent The event that is triggered before a section delete is applied to the database.
     */
    const EVENT_BEFORE_APPLY_SECTION_DELETE = 'beforeApplySectionDelete';

    /**
     * @event SectionEvent The event that is triggered after a section is deleted.
     */
    const EVENT_AFTER_DELETE_SECTION = 'afterDeleteSection';

    /**
     * @event EntryTypeEvent The event that is triggered before an entry type is saved.
     */
    const EVENT_BEFORE_SAVE_ENTRY_TYPE = 'beforeSaveEntryType';

    /**
     * @event EntryTypeEvent The event that is triggered after an entry type is saved.
     */
    const EVENT_AFTER_SAVE_ENTRY_TYPE = 'afterSaveEntryType';

    /**
     * @event EntryTypeEvent The event that is triggered before an entry type is deleted.
     */
    const EVENT_BEFORE_DELETE_ENTRY_TYPE = 'beforeDeleteEntryType';

    /**
     * @event EntryTypeEvent The event that is triggered before an entry type delete is applied to the database.
     */
    const EVENT_BEFORE_APPLY_ENTRY_TYPE_DELETE = 'beforeApplyEntryTypeDelete';

    /**
     * @event EntryTypeEvent The event that is triggered after an entry type is deleted.
     */
    const EVENT_AFTER_DELETE_ENTRY_TYPE = 'afterDeleteEntryType';

    const CONFIG_SECTIONS_KEY = 'sections';

    const CONFIG_ENTRYTYPES_KEY = 'entryTypes';

    // Properties
    // =========================================================================

    /**
     * @var
     */
    private $_allSectionIds;

    /**
     * @var
     */
    private $_editableSectionIds;

    /**
     * @var
     */
    private $_sectionsById;

    /**
     * @var bool
     */
    private $_fetchedAllSections = false;

    /**
     * @var
     */
    private $_entryTypesById;

    // Public Methods
    // =========================================================================

    // Sections
    // -------------------------------------------------------------------------

    /**
     * Returns all of the section IDs.
     *
     * ---
     *
     * ```php
     * $sectionIds = Craft::$app->sections->allSectionIds;
     * ```
     * ```twig
     * {% set sectionIds = craft.app.sections.allSectionIds %}
     * ```
     *
     * @return int[] All the sections’ IDs.
     */
    public function getAllSectionIds(): array
    {
        if ($this->_allSectionIds !== null) {
            return $this->_allSectionIds;
        }

        $this->_allSectionIds = [];

        foreach ($this->getAllSections() as $section) {
            $this->_allSectionIds[] = $section->id;
        }

        return $this->_allSectionIds;
    }

    /**
     * Returns all of the section IDs that are editable by the current user.
     *
     * ---
     *
     * ```php
     * $sectionIds = Craft::$app->sections->editableSectionIds;
     * ```
     * ```twig
     * {% set sectionIds = craft.app.sections.editableSectionIds %}
     * ```
     *
     * @return array All the editable sections’ IDs.
     */
    public function getEditableSectionIds(): array
    {
        if ($this->_editableSectionIds !== null) {
            return $this->_editableSectionIds;
        }

        $this->_editableSectionIds = [];

        foreach ($this->getAllSections() as $section) {
            if (Craft::$app->getUser()->checkPermission('editEntries:' . $section->uid)) {
                $this->_editableSectionIds[] = $section->id;
            }
        }

        return $this->_editableSectionIds;
    }

    /**
     * Returns all sections.
     *
     * ---
     *
     * ```php
     * $sections = Craft::$app->sections->allSections;
     * ```
     * ```twig
     * {% set sections = craft.app.sections.allSections %}
     * ```
     *
     * @return Section[] All the sections.
     */
    public function getAllSections(): array
    {
        if ($this->_fetchedAllSections) {
            return array_values($this->_sectionsById);
        }

        $results = $this->_createSectionQuery()
            ->all();

        $this->_sectionsById = [];

        foreach ($results as $result) {
            $section = new Section($result);
            $this->_sectionsById[$section->id] = $section;
        }

        $this->_fetchedAllSections = true;

        return array_values($this->_sectionsById);
    }

    /**
     * Returns all editable sections.
     *
     * ---
     *
     * ```php
     * $sections = Craft::$app->sections->editableSections;
     * ```
     * ```twig
     * {% set sections = craft.app.sections.editableSections %}
     * ```
     *
     * @return Section[] All the editable sections.
     */
    public function getEditableSections(): array
    {
        $editableSectionIds = $this->getEditableSectionIds();
        $editableSections = [];

        foreach ($this->getAllSections() as $section) {
            if (in_array($section->id, $editableSectionIds, false)) {
                $editableSections[] = $section;
            }
        }

        return $editableSections;
    }


    /**
     * Returns all sections of a given type.
     *
     * ---
     *
     * ```php
     * use craft\models\Section;
     *
     * $singles = Craft::$app->sections->getSectionsByType(Section::TYPE_SINGLE);
     * ```
     * ```twig
     * {% set singles = craft.app.sections.getSectionsByType('single') %}
     * ```
     *
     * @param string $type The section type (`single`, `channel`, or `structure`)
     * @return Section[] All the sections of the given type.
     */
    public function getSectionsByType(string $type): array
    {
        $sections = [];

        foreach ($this->getAllSections() as $section) {
            if ($section->type == $type) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * Gets the total number of sections.
     *
     * ---
     *
     * ```php
     * $total = Craft::$app->sections->totalSections;
     * ```
     * ```twig
     * {% set total = craft.app.sections.totalSections %}
     * ```
     *
     * @return int
     */
    public function getTotalSections(): int
    {
        return count($this->getAllSectionIds());
    }

    /**
     * Gets the total number of sections that are editable by the current user.
     *
     * ---
     *
     * ```php
     * $total = Craft::$app->sections->totalEditableSections;
     * ```
     * ```twig
     * {% set total = craft.app.sections.totalEditableSections %}
     * ```
     *
     * @return int
     */
    public function getTotalEditableSections(): int
    {
        return count($this->getEditableSectionIds());
    }

    /**
     * Returns a section by its ID.
     *
     * ---
     *
     * ```php
     * $section = Craft::$app->sections->getSectionById(1);
     * ```
     * ```twig
     * {% set section = craft.app.sections.getSectionById(1) %}
     * ```
     *
     * @param int $sectionId
     * @return Section|null
     */
    public function getSectionById(int $sectionId)
    {
        if (!$sectionId) {
            return null;
        }

        if ($this->_sectionsById !== null && array_key_exists($sectionId, $this->_sectionsById)) {
            return $this->_sectionsById[$sectionId];
        }

        // If we've already fetched all sections we can save ourselves a trip to
        // the DB for section IDs that don't exist
        if ($this->_fetchedAllSections) {
            return null;
        }

        $result = $this->_createSectionQuery()
            ->where(['sections.id' => $sectionId])
            ->one();

        return $this->_sectionsById[$sectionId] = $result ? new Section($result) : null;
    }

    /**
     * Gets a section by its UID.
     *
     * ---
     *
     * ```php
     * $section = Craft::$app->sections->getSectionByUid('b3a9eef3-9444-4995-84e2-6dc6b60aebd2');
     * ```
     * ```twig
     * {% set section = craft.app.sections.getSectionByUid('b3a9eef3-9444-4995-84e2-6dc6b60aebd2') %}
     * ```
     *
     * @param string $uid
     * @return Section|null
     */
    public function getSectionByUid(string $uid)
    {
        $result = $this->_createSectionQuery()
            ->where(['sections.uid' => $uid])
            ->one();

        if (!$result) {
            return null;
        }

        $section = new Section($result);
        $this->_sectionsById[$section->id] = $section;
        return $section;
    }

    /**
     * Gets a section by its handle.
     *
     * ---
     *
     * ```php
     * $section = Craft::$app->sections->getSectionByHandle('news');
     * ```
     * ```twig
     * {% set section = craft.app.sections.getSectionByHandle('news') %}
     * ```
     *
     * @param string $sectionHandle
     * @return Section|null
     */
    public function getSectionByHandle(string $sectionHandle)
    {
        $result = $this->_createSectionQuery()
            ->where(['sections.handle' => $sectionHandle])
            ->one();

        if (!$result) {
            return null;
        }

        $section = new Section($result);
        $this->_sectionsById[$section->id] = $section;
        return $section;
    }

    /**
     * Returns a section’s site-specific settings.
     *
     * @param int $sectionId
     * @return Section_SiteSettings[] The section’s site-specific settings.
     */
    public function getSectionSiteSettings(int $sectionId): array
    {
        $siteSettings = (new Query())
            ->select([
                'sections_sites.id',
                'sections_sites.sectionId',
                'sections_sites.siteId',
                'sections_sites.enabledByDefault',
                'sections_sites.hasUrls',
                'sections_sites.uriFormat',
                'sections_sites.template',
            ])
            ->from(['{{%sections_sites}} sections_sites'])
            ->innerJoin('{{%sites}} sites', '[[sites.id]] = [[sections_sites.siteId]]')
            ->where(['sections_sites.sectionId' => $sectionId])
            ->orderBy(['sites.sortOrder' => SORT_ASC])
            ->all();

        foreach ($siteSettings as $key => $value) {
            $siteSettings[$key] = new Section_SiteSettings($value);
        }

        return $siteSettings;
    }

    /**
     * Saves a section.
     *
     * ---
     *
     * ```php
     * use craft\models\Section;
     * use craft\models\Section_SiteSettings;
     *
     * $section = new Section([
     *     'name' => 'News',
     *     'handle' => 'news',
     *     'type' => Section::TYPE_CHANNEL,
     *     'siteSettings' => [
     *         new Section_SiteSettings([
     *             'siteId' => Craft::$app->sites->getPrimarySite()->id,
     *             'enabledByDefault' => true,
     *             'hasUrls' => true,
     *             'uriFormat' => 'foo/{slug}',
     *             'template' => 'foo/_entry',
     *         ]),
     *     ]
     * ]);
     *
     * $success = Craft::$app->sections->saveSection($section);
     * ```
     *
     * @param Section $section The section to be saved
     * @param bool $runValidation Whether the section should be validated
     * @return bool
     * @throws SectionNotFoundException if $section->id is invalid
     * @throws \Throwable if reasons
     */
    public function saveSection(Section $section, bool $runValidation = true): bool
    {
        $isNewSection = !$section->id;

        // Fire a 'beforeSaveSection' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_SECTION)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_SECTION, new SectionEvent([
                'section' => $section,
                'isNew' => $isNewSection
            ]));
        }

        if ($runValidation && !$section->validate()) {
            Craft::info('Section not saved due to validation error.', __METHOD__);
            return false;
        }

        if ($isNewSection) {
            $section->uid = StringHelper::UUID();
        } else if (!$section->uid) {
            $section->uid = Db::uidById('{{%sections}}', $section->id);
        }

        // Main section settings
        if ($section->type === Section::TYPE_SINGLE) {
            $section->propagateEntries = true;
        }

        // Assemble the section config
        // -----------------------------------------------------------------

        $projectConfig = Craft::$app->getProjectConfig();

        $configData = [
            'name' => $section->name,
            'handle' => $section->handle,
            'type' => $section->type,
            'enableVersioning' => (bool)$section->enableVersioning,
            'propagateEntries' => (bool)$section->propagateEntries,
            'siteSettings' => [],
        ];

        if ($section->type === Section::TYPE_STRUCTURE) {
            $sectionRecord = $this->_getSectionRecord($section->uid);
            if ($sectionRecord->structureId) {
                $structureUid = Db::uidById('{{%structures}}', $sectionRecord->structureId);
            } else {
                $structureUid = StringHelper::UUID();
            }

            $configData['structure'] = [
                'uid' => $structureUid,
                'maxLevels' => $section->maxLevels,
            ];
        }

        // Load the existing entry type info
        if (!$isNewSection) {
            $configData[self::CONFIG_ENTRYTYPES_KEY] = $projectConfig->get(self::CONFIG_SECTIONS_KEY . '.' . $section->uid . '.' . self::CONFIG_ENTRYTYPES_KEY);
        }

        // Get the site settings
        $allSiteSettings = $section->getSiteSettings();

        if (empty($allSiteSettings)) {
            throw new Exception('Tried to save a section without any site settings');
        }

        foreach ($allSiteSettings as $siteId => $settings) {
            $siteUid = Db::uidById('{{%sites}}', $siteId);
            $configData['siteSettings'][$siteUid] = [
                'enabledByDefault' => $settings['enabledByDefault'],
                'hasUrls' => $settings['hasUrls'],
                'uriFormat' => $settings['uriFormat'],
                'template' => $settings['template'],
            ];
        }

        // Do everything that follows in a transaction so no DB changes will be
        // saved if an exception occurs that ends up preventing the project config
        // changes from getting saved
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // Save the section config
            // -----------------------------------------------------------------

            $configPath = self::CONFIG_SECTIONS_KEY . '.' . $section->uid;
            $projectConfig->set($configPath, $configData);

            if ($isNewSection) {
                $section->id = Db::idByUid('{{%sections}}', $section->uid);
            }

            // Make sure there's at least one entry type for this section
            // -----------------------------------------------------------------

            if (!$isNewSection) {
                $entryTypeExists = (new Query())
                    ->select(['id'])
                    ->from(['{{%entrytypes}}'])
                    ->where(['sectionId' => $section->id])
                    ->exists();
            } else {
                $entryTypeExists = false;
            }

            if (!$entryTypeExists) {
                $entryType = new EntryType();
                $entryType->sectionId = $section->id;
                $entryType->name = $section->name;
                $entryType->handle = $section->handle;

                if ($section->type === Section::TYPE_SINGLE) {
                    $entryType->hasTitleField = false;
                    $entryType->titleLabel = null;
                    $entryType->titleFormat = '{section.name|raw}';
                } else {
                    $entryType->hasTitleField = true;
                    $entryType->titleLabel = Craft::t('app', 'Title');
                    $entryType->titleFormat = null;
                }

                $this->saveEntryType($entryType);
                $section->setEntryTypes([$entryType]);
            }

            // Special handling for Single sections
            // -----------------------------------------------------------------

            if ($section->type === Section::TYPE_SINGLE) {
                // Ensure & get the single entry
                $entry = $this->_ensureSingleEntry($section);

                // Deal with the section's entry types
                if (!$isNewSection) {
                    foreach ($this->getEntryTypesBySectionId($section->id) as $entryType) {
                        if ($entryType->id == $entry->typeId) {
                            // This is *the* entry's type. Make sure its name & handle match the section's
                            if (
                                ($entryType->name !== ($entryType->name = $section->name)) ||
                                ($entryType->handle !== ($entryType->handle = $section->handle))
                            ) {
                                $this->saveEntryType($entryType);
                            }

                            $section->setEntryTypes([$entryType]);
                        } else {
                            // We don't need this one anymore
                            $this->deleteEntryType($entryType);
                        }
                    }
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Handle section change
     *
     * @param ConfigEvent $event
     */
    public function handleChangedSection(ConfigEvent $event)
    {
        ProjectConfigHelper::ensureAllSitesProcessed();
        ProjectConfigHelper::ensureAllFieldsProcessed();

        $sectionUid = $event->tokenMatches[0];
        $data = $event->newValue;

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $structureData = $data['structure'] ?? null;
            $siteSettingData = $data['siteSettings'];

            // Basic data
            $sectionRecord = $this->_getSectionRecord($sectionUid);
            $oldSectionRecord = clone $sectionRecord;
            $sectionRecord->uid = $sectionUid;
            $sectionRecord->name = $data['name'];
            $sectionRecord->handle = $data['handle'];
            $sectionRecord->type = $data['type'];
            $sectionRecord->enableVersioning = (bool)$data['enableVersioning'];
            $sectionRecord->propagateEntries = (bool)$data['propagateEntries'];

            $structure = $structureData ? (Craft::$app->getStructures()->getStructureByUid($structureData['uid']) ?? new Structure()) : new Structure();

            $isNewSection = $sectionRecord->getIsNewRecord();
            $isNewStructure = !(bool)$structure->id;

            if ($data['type'] === Section::TYPE_STRUCTURE) {
                $structure->maxLevels = $structureData['maxLevels'];
                Craft::$app->getStructures()->saveStructure($structure);

                $sectionRecord->structureId = $structure->id;
            } else {
                /** @noinspection PhpUndefinedVariableInspection */
                if (!$isNewSection && $structure->id) {
                    // Delete the old one
                    Craft::$app->getStructures()->deleteStructureById($structure->id);
                    $sectionRecord->structureId = null;
                }
            }

            $sectionRecord->save(false);

            // Update the site settings
            // -----------------------------------------------------------------

            if (!$isNewSection) {
                // Get the old section site settings
                $allOldSiteSettingsRecords = Section_SiteSettingsRecord::find()
                    ->where(['sectionId' => $sectionRecord->id])
                    ->indexBy('siteId')
                    ->all();
            } else {
                $allOldSiteSettingsRecords = [];
            }

            $siteIdMap = Db::idsByUids('{{%sites}}', array_keys($siteSettingData));

            foreach ($siteSettingData as $siteUid => $siteSettings) {
                $siteId = $siteIdMap[$siteUid];

                // Was this already selected?
                if (!$isNewSection && isset($allOldSiteSettingsRecords[$siteId])) {
                    $siteSettingsRecord = $allOldSiteSettingsRecords[$siteId];
                } else {
                    $siteSettingsRecord = new Section_SiteSettingsRecord();
                    $siteSettingsRecord->sectionId = $sectionRecord->id;
                    $siteSettingsRecord->siteId = $siteId;
                }

                $siteSettingsRecord->enabledByDefault = $siteSettings['enabledByDefault'];

                if ($siteSettingsRecord->hasUrls = $siteSettings['hasUrls']) {
                    $siteSettingsRecord->uriFormat = $siteSettings['uriFormat'];
                    $siteSettingsRecord->template = $siteSettings['template'];
                } else {
                    $siteSettingsRecord->uriFormat = $siteSettings['uriFormat'] = null;
                    $siteSettingsRecord->template = $siteSettings['template'] = null;
                }

                $siteSettingsRecord->save(false);
            }

            if (!$isNewSection) {
                // Drop any sites that are no longer being used, as well as the associated entry/element site
                // rows
                $affectedSiteUids = array_keys($siteSettingData);

                /** @noinspection PhpUndefinedVariableInspection */
                foreach ($allOldSiteSettingsRecords as $siteId => $siteSettingsRecord) {
                    $siteUid = array_search($siteId, $siteIdMap, false);
                    if (!in_array($siteUid, $affectedSiteUids, false)) {
                        $siteSettingsRecord->delete();
                    }
                }
            }

            // If the section was just converted to a Structure,
            // add the existing entries to the structure
            // -----------------------------------------------------------------

            if (
                $sectionRecord->type === Section::TYPE_STRUCTURE &&
                !$isNewSection &&
                $isNewStructure
            ) {
                $this->_populateNewStructure($sectionRecord, $oldSectionRecord, array_keys($allOldSiteSettingsRecords));
            }

            // Finally, deal with the existing entries...
            // -----------------------------------------------------------------

            if (!$isNewSection) {
                if ($oldSectionRecord->propagateEntries) {
                    // Find a site that the section was already enabled in, and still is
                    $oldSiteIds = array_keys($allOldSiteSettingsRecords);
                    $newSiteIds = $siteIdMap;
                    $persistentSiteIds = array_values(array_intersect($newSiteIds, $oldSiteIds));

                    // Try to make that the primary site, if it's in the list
                    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
                    if (!in_array($siteId, $persistentSiteIds, false)) {
                        $siteId = $persistentSiteIds[0];
                    }

                    Craft::$app->getQueue()->push(new ResaveElements([
                        'description' => Craft::t('app', 'Resaving {section} entries', [
                            'section' => $sectionRecord->name,
                        ]),
                        'elementType' => Entry::class,
                        'criteria' => [
                            'siteId' => $siteId,
                            'sectionId' => $sectionRecord->id,
                            'status' => null,
                            'enabledForSite' => false,
                        ]
                    ]));
                } else {
                    // Resave entries for each site
                    $sitesService = Craft::$app->getSites();
                    foreach ($siteSettingData as $siteUid => $siteSettings) {
                        Craft::$app->getQueue()->push(new ResaveElements([
                            'description' => Craft::t('app', 'Resaving {section} entries ({site})', [
                                'section' => $sectionRecord->name,
                                'site' => $sitesService->getSiteByUid($siteUid)->name
                            ]),
                            'elementType' => Entry::class,
                            'criteria' => [
                                'siteId' => $siteIdMap[$siteUid],
                                'sectionId' => $sectionRecord->id,
                                'status' => null,
                                'enabledForSite' => false,
                            ]
                        ]));
                    }
                }
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // Clear caches
        $this->_allSectionIds = null;
        $this->_editableSectionIds = null;
        unset($this->_sectionsById[$sectionRecord->id]);
        $this->_fetchedAllSections = false;

        /** @var Section $section */
        $section = $this->getSectionById($sectionRecord->id);

        // If this is a Single and no entry type changes need to be processed,
        // ensure that the section has its one and only entry
        if (
            $section->type === Section::TYPE_SINGLE &&
            !Craft::$app->getProjectConfig()->areChangesPending($event->path . '.' . self::CONFIG_ENTRYTYPES_KEY)
        ) {
            $this->_ensureSingleEntry($section);
        }

        // Fire an 'afterSaveSection' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_SECTION)) {
            $this->trigger(self::EVENT_AFTER_SAVE_SECTION, new SectionEvent([
                'section' => $section,
                'isNew' => $isNewSection
            ]));
        }
    }

    /**
     * Deletes a section by its ID.
     *
     * ---
     *
     * ```php
     * $success = Craft::$app->sections->deleteSectionById(1);
     * ```
     *
     * @param int $sectionId
     * @return bool Whether the section was deleted successfully
     * @throws \Throwable if reasons
     */
    public function deleteSectionById(int $sectionId): bool
    {
        $section = $this->getSectionById($sectionId);

        if (!$section) {
            return false;
        }

        return $this->deleteSection($section);
    }

    /**
     * Deletes a section.
     *
     * ---
     *
     * ```php
     * $success = Craft::$app->sections->deleteSection($section);
     * ```
     *
     * @param Section $section
     * @return bool Whether the section was deleted successfully
     * @throws \Throwable if reasons
     */
    public function deleteSection(Section $section): bool
    {
        // Fire a 'beforeDeleteSection' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_SECTION)) {
            $this->trigger(self::EVENT_BEFORE_DELETE_SECTION, new SectionEvent([
                'section' => $section,
            ]));
        }

        Craft::$app->getProjectConfig()->remove(self::CONFIG_SECTIONS_KEY . '.' . $section->uid);
        return true;
    }

    /**
     * Handle a section getting deleted
     *
     * @param ConfigEvent $event
     */
    public function handleDeletedSection(ConfigEvent $event)
    {
        $uid = $event->tokenMatches[0];
        $sectionRecord = $this->_getSectionRecord($uid);

        if (!$sectionRecord->id) {
            return;
        }

        /** @var Section $section */
        $section = $this->getSectionById($sectionRecord->id);

        // Fire a 'beforeApplySectionDelete' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_APPLY_SECTION_DELETE)) {
            $this->trigger(self::EVENT_BEFORE_APPLY_SECTION_DELETE, new SectionEvent([
                'section' => $section,
            ]));
        }

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            // Nuke the field layouts first.
            $entryTypeIds = [];
            $entryTypes = $this->getEntryTypesBySectionId($sectionRecord->id);

            foreach ($entryTypes as $entryType) {
                $entryTypeIds[] = $entryType->id;
            }

            // Delete the field layout(s)
            $fieldLayoutIds = (new Query())
                ->select(['fieldLayoutId'])
                ->from(['{{%entrytypes}}'])
                ->where(['id' => $entryTypeIds])
                ->andWhere(['not', ['fieldLayoutId' => null]])
                ->column();

            if (!empty($fieldLayoutIds)) {
                Craft::$app->getFields()->deleteLayoutById($fieldLayoutIds);
            }

            // Delete the entries
            // (loop through all the sites in case there are any lingering entries from unsupported sites
            $entryQuery = Entry::find()
                ->anyStatus()
                ->sectionId($sectionRecord->id);

            $elementsService = Craft::$app->getElements();
            foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
                foreach ($entryQuery->siteId($siteId)->each() as $entry) {
                    $elementsService->deleteElement($entry);
                }
            }

            // Delete the structure, if there is one
            $structureId = (new Query())
                ->select(['structureId'])
                ->from(['{{%sections}}'])
                ->where(['id' => $sectionRecord->id])
                ->scalar();

            if ($structureId) {
                Craft::$app->getStructures()->deleteStructureById($structureId);
            }

            // Delete the section.
            Craft::$app->getDb()->createCommand()
                ->delete('{{%sections}}', ['id' => $sectionRecord->id])
                ->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // Clear caches
        $this->_allSectionIds = null;
        $this->_editableSectionIds = null;
        unset($this->_sectionsById[$section->id]);

        // Fire an 'afterDeleteSection' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_SECTION)) {
            $this->trigger(self::EVENT_AFTER_DELETE_SECTION, new SectionEvent([
                'section' => $section,
            ]));
        }
    }

    /**
     * Returns whether a section’s entries have URLs for the given site ID, and if the section’s template path is valid.
     *
     * @param Section $section
     * @param int $siteId
     * @return bool
     */
    public function isSectionTemplateValid(Section $section, int $siteId): bool
    {
        $sectionSiteSettings = $section->getSiteSettings();

        if (isset($sectionSiteSettings[$siteId]) && $sectionSiteSettings[$siteId]->hasUrls) {
            // Set Craft to the site template mode
            $view = Craft::$app->getView();
            $oldTemplateMode = $view->getTemplateMode();
            $view->setTemplateMode($view::TEMPLATE_MODE_SITE);

            // Does the template exist?
            $templateExists = Craft::$app->getView()->doesTemplateExist((string)$sectionSiteSettings[$siteId]->template);

            // Restore the original template mode
            $view->setTemplateMode($oldTemplateMode);

            if ($templateExists) {
                return true;
            }
        }

        return false;
    }

    // Entry Types
    // -------------------------------------------------------------------------

    /**
     * Returns a section’s entry types.
     *
     * ---
     *
     * ```php
     * $entryTypes = Craft::$app->sections->getEntryTypesBySectionId(1);
     * ```
     *
     * @param int $sectionId
     * @return EntryType[]
     */
    public function getEntryTypesBySectionId(int $sectionId): array
    {
        $results = $this->_createEntryTypeQuery()
            ->where(['sectionId' => $sectionId])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        foreach ($results as $key => $result) {
            $results[$key] = new EntryType($result);
        }

        return $results;
    }

    /**
     * Returns an entry type by its ID.
     *
     * ---
     *
     * ```php
     * $entryType = Craft::$app->sections->getEntryTypeById(1);
     * ```
     *
     * @param int $entryTypeId
     * @return EntryType|null
     */
    public function getEntryTypeById(int $entryTypeId)
    {
        if (!$entryTypeId) {
            return null;
        }

        if ($this->_entryTypesById !== null && array_key_exists($entryTypeId, $this->_entryTypesById)) {
            return $this->_entryTypesById[$entryTypeId];
        }

        $result = $this->_createEntryTypeQuery()
            ->where(['id' => $entryTypeId])
            ->one();

        return $this->_entryTypesById[$entryTypeId] = $result ? new EntryType($result) : null;
    }

    /**
     * Returns entry types that have a given handle.
     *
     * ---
     *
     * ```php
     * $entryTypes = Craft::$app->sections->getEntryTypesByHandle('article');
     * ```
     *
     * @param string $entryTypeHandle
     * @return EntryType[]
     */
    public function getEntryTypesByHandle(string $entryTypeHandle): array
    {
        $results = $this->_createEntryTypeQuery()
            ->where(['handle' => $entryTypeHandle])
            ->all();

        foreach ($results as $key => $result) {
            $results[$key] = new EntryType($result);
        }

        return $results;
    }

    /**
     * Saves an entry type.
     *
     * @param EntryType $entryType The entry type to be saved
     * @param bool $runValidation Whether the entry type should be validated
     * @return bool Whether the entry type was saved successfully
     * @throws EntryTypeNotFoundException if $entryType->id is invalid
     * @throws \Throwable if reasons
     */
    public function saveEntryType(EntryType $entryType, bool $runValidation = true): bool
    {
        $isNewEntryType = !$entryType->id;

        // Fire a 'beforeSaveEntryType' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_ENTRY_TYPE)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_ENTRY_TYPE, new EntryTypeEvent([
                'entryType' => $entryType,
                'isNew' => $isNewEntryType,
            ]));
        }

        if ($runValidation && !$entryType->validate()) {
            Craft::info('Entry type not saved due to validation error.', __METHOD__);
            return false;
        }

        if ($isNewEntryType) {
            $entryType->uid = StringHelper::UUID();

            $maxSortOrder = (new Query())
                ->from(['{{%entrytypes}}'])
                ->where(['sectionId' => $entryType->sectionId])
                ->max('[[sortOrder]]');
            $sortOrder = $maxSortOrder ? $maxSortOrder + 1 : 1;
        } else {
            $entryTypeRecord = EntryTypeRecord::findOne($entryType->id);

            if (!$entryTypeRecord) {
                throw new EntryTypeNotFoundException("No entry type exists with the ID '{$entryType->id}'");
            }

            $entryType->uid = $entryTypeRecord->uid;
            $sortOrder = $entryTypeRecord->sortOrder;
        }

        $section = $entryType->getSection();

        $projectConfig = Craft::$app->getProjectConfig();
        $configData = [
            'name' => $entryType->name,
            'handle' => $entryType->handle,
            'hasTitleField' => $entryType->hasTitleField,
            'titleLabel' => $entryType->titleLabel,
            'titleFormat' => $entryType->titleFormat,
            'sortOrder' => $sortOrder,
        ];

        $fieldLayout = $entryType->getFieldLayout();
        $fieldLayoutConfig = $fieldLayout->getConfig();

        if ($fieldLayoutConfig) {
            if (empty($fieldLayout->id)) {
                $layoutUid = StringHelper::UUID();
                $fieldLayout->uid = $layoutUid;
            } else {
                $layoutUid = Db::uidById('{{%fieldlayouts}}', $fieldLayout->id);
            }

            $configData['fieldLayouts'] = [
                $layoutUid => $fieldLayoutConfig
            ];
        }

        $configPath = self::CONFIG_SECTIONS_KEY . '.' . $section->uid . '.' . self::CONFIG_ENTRYTYPES_KEY . '.' . $entryType->uid;
        $projectConfig->set($configPath, $configData);

        if ($isNewEntryType) {
            $entryType->id = Db::idByUid('{{%entrytypes}}', $entryType->uid);
        }

        return true;
    }

    /**
     * Handle entry type change
     *
     * @param ConfigEvent $event
     */
    public function handleChangedEntryType(ConfigEvent $event)
    {
        list($sectionUid, $entryTypeUid) = $event->tokenMatches;
        $data = $event->newValue;

        // Make sure fields are processed
        ProjectConfigHelper::ensureAllSitesProcessed();
        ProjectConfigHelper::ensureAllFieldsProcessed();

        Craft::$app->getProjectConfig()->processConfigChanges(self::CONFIG_SECTIONS_KEY . '.' . $sectionUid);

        $section = $this->getSectionByUid($sectionUid);
        $entryTypeRecord = $this->_getEntryTypeRecord($entryTypeUid);

        if (!$section || !$entryTypeRecord) {
            return;
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $isNewEntryType = $entryTypeRecord->getIsNewRecord();

            $entryTypeRecord->name = $data['name'];
            $entryTypeRecord->handle = $data['handle'];
            $entryTypeRecord->hasTitleField = $data['hasTitleField'];
            $entryTypeRecord->titleLabel = $data['titleLabel'];
            $entryTypeRecord->titleFormat = $data['titleFormat'];
            $entryTypeRecord->sortOrder = $data['sortOrder'];
            $entryTypeRecord->sectionId = $section->id;

            if (!empty($data['fieldLayouts'])) {
                $fields = Craft::$app->getFields();

                // Delete the field layout
                $fields->deleteLayoutById($entryTypeRecord->fieldLayoutId);

                //Create the new layout
                $layout = FieldLayout::createFromConfig(reset($data['fieldLayouts']));
                $layout->type = Entry::class;
                $layout->uid = key($data['fieldLayouts']);
                $fields->saveLayout($layout);
                $entryTypeRecord->fieldLayoutId = $layout->id;
            } else {
                $entryTypeRecord->fieldLayoutId = null;
            }

            // Save the entry type
            $entryTypeRecord->save(false);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // Clear caches
        unset($this->_entryTypesById[$entryTypeRecord->id]);

        /** @var EntryType $entryType */
        $entryType = $this->getEntryTypeById($entryTypeRecord->id);

        // If this is for a Single section, ensure its entry exists
        if ($section->type === Section::TYPE_SINGLE) {
            $this->_ensureSingleEntry($section);
        } else if (!$isNewEntryType) {
            // Re-save the entries of this type
            $allSiteSettings = $section->getSiteSettings();

            if ($section->propagateEntries) {
                $siteIds = array_keys($allSiteSettings);

                Craft::$app->getQueue()->push(new ResaveElements([
                    'description' => Craft::t('app', 'Resaving {type} entries', [
                        'type' => ($section->type !== Section::TYPE_SINGLE ? $section->name . ' - ' : '') . $entryType->name,
                    ]),
                    'elementType' => Entry::class,
                    'criteria' => [
                        'siteId' => $siteIds[0],
                        'sectionId' => $section->id,
                        'typeId' => $entryType->id,
                        'status' => null,
                        'enabledForSite' => false,
                    ]
                ]));
            } else {
                foreach ($allSiteSettings as $siteId => $siteSettings) {
                    Craft::$app->getQueue()->push(new ResaveElements([
                        'description' => Craft::t('app', 'Resaving {type} entries ({site})', [
                            'type' => $entryType->name,
                            'site' => $siteSettings->getSite()->name,
                        ]),
                        'elementType' => Entry::class,
                        'criteria' => [
                            'siteId' => $siteId,
                            'sectionId' => $section->id,
                            'typeId' => $entryType->id,
                            'status' => null,
                            'enabledForSite' => false,
                        ]
                    ]));
                }
            }
        }

        // Fire an 'afterSaveEntryType' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_ENTRY_TYPE)) {
            $this->trigger(self::EVENT_AFTER_SAVE_ENTRY_TYPE, new EntryTypeEvent([
                'entryType' => $entryType,
                'isNew' => $isNewEntryType,
            ]));
        }
    }

    /**
     * Reorders entry types.
     *
     * @param array $entryTypeIds
     * @return bool Whether the entry types were reordered successfully
     * @throws \Throwable if reasons
     */
    public function reorderEntryTypes(array $entryTypeIds): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $sectionRecord = null;

        $uidsByIds = Db::uidsByIds('{{%entrytypes}}', $entryTypeIds);

        foreach ($entryTypeIds as $entryTypeOrder => $entryTypeId) {
            if (!empty($uidsByIds[$entryTypeId])) {
                $entryTypeUid = $uidsByIds[$entryTypeId];
                $entryTypeRecord = $this->_getEntryTypeRecord($entryTypeUid);

                if (!$sectionRecord) {
                    $sectionRecord = SectionRecord::findOne($entryTypeRecord->sectionId);
                }

                $configPath = self::CONFIG_SECTIONS_KEY . '.' . $sectionRecord->uid . '.' . self::CONFIG_ENTRYTYPES_KEY . '.' . $entryTypeUid;
                $projectConfig->set($configPath . '.sortOrder', $entryTypeOrder + 1);
            }
        }


        return true;
    }

    /**
     * Deletes an entry type by its ID.
     *
     * ---
     *
     * ```php
     * $success = Craft::$app->sections->deleteEntryTypeById(1);
     * ```
     *
     * @param int $entryTypeId
     * @return bool Whether the entry type was deleted successfully
     * @throws \Throwable if reasons
     */
    public function deleteEntryTypeById(int $entryTypeId): bool
    {
        $entryType = $this->getEntryTypeById($entryTypeId);

        if (!$entryType) {
            return false;
        }

        return $this->deleteEntryType($entryType);
    }

    /**
     * Deletes an entry type.
     *
     * ---
     *
     * ```php
     * $success = Craft::$app->sections->deleteEntry($entryType);
     * ```
     *
     * @param EntryType $entryType
     * @return bool Whether the entry type was deleted successfully
     * @throws \Throwable if reasons
     */
    public function deleteEntryType(EntryType $entryType): bool
    {
        // Fire a 'beforeSaveEntryType' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE_ENTRY_TYPE)) {
            $this->trigger(self::EVENT_BEFORE_DELETE_ENTRY_TYPE, new EntryTypeEvent([
                'entryType' => $entryType,
            ]));
        }

        $entryTypeUid = $entryType->uid;
        $section = $entryType->getSection();
        $sectionUid = $section->uid;

        Craft::$app->getProjectConfig()->remove(self::CONFIG_SECTIONS_KEY . '.' . $sectionUid . '.' . self::CONFIG_ENTRYTYPES_KEY . '.' . $entryTypeUid);
        return true;
    }

    /**
     * Handle an entry type getting deleted
     *
     * @param ConfigEvent $event
     */
    public function handleDeletedEntryType(ConfigEvent $event)
    {
        $uid = $event->tokenMatches[1];
        $entryTypeRecord = $this->_getEntryTypeRecord($uid);

        if (!$entryTypeRecord->id) {
            return;
        }

        /** @var EntryType $entryType */
        $entryType = $this->getEntryTypeById($entryTypeRecord->id);

        // Fire a 'beforeApplyEntryTypeDelete' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_APPLY_ENTRY_TYPE_DELETE)) {
            $this->trigger(self::EVENT_BEFORE_APPLY_ENTRY_TYPE_DELETE, new EntryTypeEvent([
                'entryType' => $entryType,
            ]));
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            if ($entryTypeRecord->fieldLayoutId) {
                Craft::$app->getFields()->deleteLayoutById($entryTypeRecord->fieldLayoutId);
            }

            // Delete the entries
            // (loop through all the sites in case there are any lingering entries from unsupported sites
            $entryQuery = Entry::find()
                ->anyStatus()
                ->typeId($entryTypeRecord->id);

            $elementsService = Craft::$app->getElements();
            foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
                foreach ($entryQuery->siteId($siteId)->each() as $entry) {
                    $elementsService->deleteElement($entry);
                }
            }

            // Delete the entry type.
            Craft::$app->getDb()->createCommand()
                ->delete('{{%entrytypes}}', ['id' => $entryTypeRecord->id])
                ->execute();

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // Clear caches
        unset($this->_entryTypesById[$entryType->id]);

        // Fire an 'afterDeleteEntryType' event
        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE_ENTRY_TYPE)) {
            $this->trigger(self::EVENT_AFTER_DELETE_ENTRY_TYPE, new EntryTypeEvent([
                'entryType' => $entryType,
            ]));
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a Query object prepped for retrieving sections.
     *
     * @return Query
     */
    private function _createSectionQuery(): Query
    {
        return (new Query())
            ->select([
                'sections.id',
                'sections.structureId',
                'sections.name',
                'sections.handle',
                'sections.type',
                'sections.enableVersioning',
                'sections.propagateEntries',
                'sections.uid',
                'structures.maxLevels',
            ])
            ->leftJoin('{{%structures}} structures', '[[structures.id]] = [[sections.structureId]]')
            ->from(['{{%sections}} sections'])
            ->orderBy(['name' => SORT_ASC]);
    }

    /**
     * Ensures that the given Single section has its one and only entry, and returns it.
     *
     * @param Section $section
     * @return Entry The
     * @see saveSection()
     * @throws Exception if reasons
     */
    private function _ensureSingleEntry(Section $section): Entry
    {
        // Get all the entries that currently exist for this section
        // ---------------------------------------------------------------------

        $allSiteUids = array_keys(Craft::$app->getProjectConfig()->get(self::CONFIG_SECTIONS_KEY . '.' . $section->uid . '.siteSettings'));

        $entryData = (new Query())
            ->select([
                'e.id',
                'typeId',
                'siteId' => (new Query())
                    ->select('es.siteId')
                    ->from('{{%elements_sites}} es')
                    ->innerJoin('{{%sites}} s', '[[s.id]] = [[es.siteId]]')
                    ->where('[[es.elementId]] = [[e.id]]')
                    ->andWhere(['in', 's.uid', $allSiteUids])
                    ->limit(1)
            ])
            ->from(['{{%entries}} e'])
            ->where(['e.sectionId' => $section->id])
            ->orderBy(['e.id' => SORT_ASC])
            ->all();

        // Get the section's entry types
        // ---------------------------------------------------------------------

        /** @var EntryType[] $entryTypes */
        $entryTypes = ArrayHelper::index($this->getEntryTypesBySectionId($section->id), 'id');

        // There should always be at least one entry type by the time this is called
        if (empty($entryTypes)) {
            throw new Exception('No entry types exist for section ' . $section->id);
        }

        // Get/save the entry
        // ---------------------------------------------------------------------

        $entry = null;

        // If there are any existing entries, find the first one with a valid typeId
        foreach ($entryData as $data) {
            if (isset($entryTypes[$data['typeId']])) {
                $entry = Entry::find()
                    ->id($data['id'])
                    ->siteId($data['siteId'])
                    ->anyStatus()
                    ->one();
                break;
            }
        }

        // Otherwise create a new one
        if ($entry === null) {
            // Create one
            $firstSiteUid = reset($allSiteUids);
            $firstEntryType = reset($entryTypes);

            $entry = new Entry();
            $entry->siteId = Db::idByUid('{{%sites}}', $firstSiteUid);
            $entry->sectionId = $section->id;
            $entry->typeId = $firstEntryType->id;
            $entry->title = $section->name;
        }

        // (Re)save it with an updated title, slug, and URI format.
        $entry->setScenario(Element::SCENARIO_ESSENTIALS);
        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new Exception('Couldn’t save single entry due to validation errors on the slug and/or URI');
        }

        // Delete any other entries in the section
        // ---------------------------------------------------------------------

        foreach ($entryData as $data) {
            if ($data['id'] != $entry->id) {
                Craft::$app->getElements()->deleteElementById($data['id'], Entry::class, $data['siteId']);
            }
        }

        return $entry;
    }

    /**
     * Adds existing entries to a newly-created structure, if the section type was just converted to Structure.
     *
     * @param SectionRecord $sectionRecord
     * @param SectionRecord $oldSectionRecord
     * @param string[] $oldSiteIds
     * @see saveSection()
     * @throws Exception if reasons
     */
    private function _populateNewStructure(SectionRecord $sectionRecord, SectionRecord $oldSectionRecord, array $oldSiteIds)
    {
        if ($oldSectionRecord->propagateEntries) {
            $siteIds = [reset($oldSiteIds)];
        } else {
            $siteIds = $oldSiteIds;
        }

        $handledEntryIds = [];
        $structuresService = Craft::$app->getStructures();

        foreach ($siteIds as $siteId) {
            // Add all of the entries to the structure
            $query = Entry::find()
                ->siteId($siteId)
                ->sectionId($sectionRecord->id)
                ->anyStatus()
                ->orderBy(['elements.id' => SORT_ASC])
                ->withStructure(false);

            if (!empty($handledEntryIds)) {
                $query->andWhere(['not', ['elements.id' => $handledEntryIds]]);
            }

            /** @var Entry $entry */
            foreach ($query->each() as $entry) {
                $structuresService->appendToRoot($sectionRecord->structureId, $entry, 'insert');
                $handledEntryIds[] = $entry->id;
            }
        }
    }

    /**
     * @return Query
     */
    private function _createEntryTypeQuery()
    {
        return (new Query())
            ->select([
                'id',
                'sectionId',
                'fieldLayoutId',
                'name',
                'handle',
                'hasTitleField',
                'titleLabel',
                'titleFormat',
                'uid',
            ])
            ->from(['{{%entrytypes}}']);
    }

    /**
     * Gets a sections's record by uid.
     *
     * @param string $uid
     * @return SectionRecord
     */
    private function _getSectionRecord(string $uid): SectionRecord
    {
        return SectionRecord::findOne(['uid' => $uid]) ?? new SectionRecord();
    }

    /**
     * Gets an entry type's record by uid.
     *
     * @param string $uid
     * @return EntryTypeRecord
     */
    private function _getEntryTypeRecord(string $uid): EntryTypeRecord
    {
        return EntryTypeRecord::findOne(['uid' => $uid]) ?? new EntryTypeRecord();
    }
}