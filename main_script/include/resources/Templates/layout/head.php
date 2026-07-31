<?php
use Core\Helper\PreferencesHelper;
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN""http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <title><?= get_language_properties('title'); ?></title>
    <meta http-equiv="cache-control" content="max-age=0"/>
    <meta http-equiv="pragma" content="no-cache"/>
    <meta http-equiv="expires" content="0"/>
    <meta http-equiv="imagetoolbar" content="no"/>
    <meta http-equiv="content-type" content="text/html; charset=UTF-8"/>
    <meta name="content-language" content="<?=get_locale(); ?>"/>
    <link href="<?=get_gpack_link_and_hash("compact.css"); ?>"
          rel="stylesheet" type="text/css"/>
    <link href="<?=get_gpack_link_and_hash("lang.css"); ?>"
          rel="stylesheet" type="text/css"/>
    <?php if (is_lowres()): ?>
        <link href="<?=get_gpack_link_and_hash("compact-lowres.css"); ?>" rel="stylesheet" type="text/css"/>

    <?php endif; ?>
    <link href="<?=get_gpack_link_and_hash("fixes.css", false); ?>?rev13" rel="stylesheet" type="text/css"/>
    <link href="openvillage.css?rev1" rel="stylesheet" type="text/css"/>

    <script type="text/javascript">
        window.ajaxToken = '<?=(isset($vars['ajaxToken']) ? $vars['ajaxToken'] : null);?>';
        window._player_uuid = '<?=(isset($vars['_player_uuid']) ? $vars['_player_uuid'] : null);?>';
    </script>
    <script type="text/javascript" src="js/default/jquery-3.2.1.min.js"></script>
    <script type="text/javascript" src="js/default/jquery.md5.min.js"></script>
    <script type="text/javascript" src="js/default/jquery.scrollbar.min.js"></script>
    <script type="text/javascript" src="js/Game/General/General.js"></script>
    <script type="text/javascript">
        <?php
        $data = [
            'Map' => [
                'Size' => [
                    'width'  => (2 * MAP_SIZE) + 1,
                    'height' => (2 * MAP_SIZE) + 1,
                    'left'   => -MAP_SIZE,
                    'right'  => MAP_SIZE,
                    'bottom' => -MAP_SIZE,
                    'top'    => MAP_SIZE,
                ],
            ],
            'Season' => detect_season(),
        ];
        ?>
        window.TravianDefaults = Object.assign(<?=json_encode($data);?>, false || {});
    </script>
    <script type="text/javascript" src="<?=get_crypt_js_link(); ?>"></script>
    <link rel="icon" href="favicon.ico" type="image/x-icon"/>
    <script type="text/javascript">
        <?php if(!(isset($vars['autoReload']) && $vars['autoReload'] == 1)):?>
        window.reload_enabled = false;
        <?php endif;?>
        Travian.Translation.add({
            'allgemein.anleitung': '<?=T("Global", "General.instructions");?>',
            'allgemein.cancel': '<?=T("Global", "General.cancel");?>',
            'allgemein.ok': '<?=T("Global", "General.ok");?>',
            'allgemein.close': '<?=T("Global", "General.close");?>',
            'allgemein.close_with_capital_c': '<?=T("Global", "General.close");?>',
            'cropfinder.keine_ergebnisse': '<?=T("Global", "cropfinder.no results found");?>'
        });
        <?php
        $buttonTemplate = '<button ><div class="button-container addHoverClick"><div class="button-background"><div class="buttonStart"><div class="buttonEnd"><div class="buttonMiddle"></div></div></div></div><div class="button-content"></div></div></button>';

        $buttonTemplate = '<button ><div class="button-container addHoverClick"><div class="button-background"><div class="buttonStart"><div class="buttonEnd"><div class="buttonMiddle"></div></div></div></div><div class="button-content"></div></div></button>';


        $eventJamHtml = '<a href="javascript:void" onclick="document.location.reload();"><img src="img/refresh.png" alt="Reload"></a>';
        ?>
        Travian.applicationId = 'OpenVillage Game';
        Travian.Game.version = '4.6';
        Travian.Game.worldId = '<?=getWorldId();?>';
        Travian.Game.speed = <?=getGameSpeed();?>;
        Travian.Game.country = '<?=get_language_properties('country'); ?>';
        Travian.Templates = {};
        Travian.Templates.ButtonTemplate = '<?=$buttonTemplate;?>';
        Travian.Game.eventJamHtml = '<?=$eventJamHtml;?>';
        Travian.Form.UnloadHelper.message = '<?=T("Global", "UnloadHelper.message");?>';
        <?php
        $preferences = PreferencesHelper::getPreferences();
        if (isset($preferences['allianceBonusesOverview'])) {
            $preferences['allianceBonusesOverview'] = json_encode($preferences['allianceBonusesOverview']);
        }
        ?>
        Travian.alphabetizeTeletypesBrushesUnwarranted = function () {
            return '<?=isset($vars['ajaxToken']) ? $vars['ajaxToken'] : null;?>';
        };
        Travian.Game.Preferences.initialize(<?=json_encode($preferences);?>);
    </script>
</head>
