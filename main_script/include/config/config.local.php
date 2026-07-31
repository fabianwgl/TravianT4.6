<?php

/**
 * Conservative, self-contained defaults for the maintained local distribution.
 * Operators can override these values from sections/config.custom.php.
 */
$config->display->showOnlinePlayers = true;
$config->display->showCopyright = false;
$config->display->showFarmsInStatistics = false;
$config->display->includeHiddenMedals = false;
$config->settings->advanced->voucherEnabled = false;
$config->custom->paymentWizardBuyGoldEnabled = false;
$config->custom->serverIsFreeGold = false;
$config->game->allowNewTribes = false;
$config->game->movement_speed_increase = 4;
$config->game->protection_time = 2 * 86400;
$config->game->dailyQuestInterval = 86400;
$config->game->starvation = true;
$config->fakeUsersCount = 0;

foreach ($config->settings->availableLanguages as $language) {
    $language->title = 'OpenVillage 4.6 · ' . $config->settings->worldId;
    $language->ForumUrl = '/docs/';
    $language->AnswersUrl = '/docs/';
}

$config->extraSettings->addFarms->enabled = false;
$config->extraSettings->generalOptions->increaseStorage->enabled = false;
$config->extraSettings->generalOptions->finishTraining->enabled = false;
$config->extraSettings->generalOptions->fasterTraining->enabled = false;
$config->extraSettings->generalOptions->smithyUpgradeAllToMax->enabled = false;
$config->extraSettings->generalOptions->academyResearchAll->enabled = false;
$config->extraSettings->generalOptions->buyAdventure->enabled = false;
$config->extraSettings->buyBuildings['enabled'] = false;
$config->extraSettings->buyResources['enabled'] = false;
$config->extraSettings->buyAnimal['enabled'] = false;
