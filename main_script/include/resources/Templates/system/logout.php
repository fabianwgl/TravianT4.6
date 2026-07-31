<?php

use Core\Config;

$config = Config::getInstance();
?>
<h4><?= T('Logout', 'thanks_for_your_visit'); ?></h4>
<p><?= T('Logout', 'cookieDesc'); ?>:</p>
<p><a class="arrow" href="login.php?del_cookie"><?= T('Logout', 'delete_cookies'); ?></a></p>
<div class="greenbox cf relogin">
    <div class="greenbox-top"></div>
    <div class="greenbox-content">
        <h5><?= T('Logout', 'back_to_the_game'); ?></h5>
        <form name="login" method="post" action="dorf1.php">
            <table class="transparent reloginTable">
                <tr class="account">
                    <th class="accountName"><?= T('Login', 'accountNameOrEmailAddress'); ?>:</th>
                    <td><input type="text" name="name" value="<?= htmlspecialchars($vars['username'], ENT_QUOTES); ?>" class="text"/></td>
                </tr>
                <tr class="pass">
                    <th><?= T('Login', 'pass'); ?></th>
                    <td><input type="password" maxlength="128" name="password" value="" class="text"/></td>
                </tr>
            </table>
            <div class="submitButton">
                <button type="submit" value="<?= T('Login', 'Login'); ?>" name="s1" id="reloginButton" class="green"
                        onclick="document.login.w.value=screen.width+':'+screen.height;">
                    <div class="button-container addHoverClick">
                        <div class="button-background"><div class="buttonStart"><div class="buttonEnd"><div class="buttonMiddle"></div></div></div></div>
                        <div class="button-content"><?= T('Login', 'Login'); ?></div>
                    </div>
                </button>
            </div>
            <input type="hidden" name="w" value=""/>
            <input type="hidden" name="login" value="<?= (int)$vars['time']; ?>"/>
            <?php if ($vars['lowRes']): ?>
                <input type="hidden" name="lowRes" value="<?= htmlspecialchars($vars['lowRes'], ENT_QUOTES); ?>"/>
            <?php endif; ?>
        </form>
    </div>
    <div class="greenbox-bottom"></div>
</div>
<?php if (!empty($config->dynamic->loginInfoTitle) && !empty($config->dynamic->loginInfoHTML)): ?>
    <br/>
    <div class="roundedCornersBox big">
        <h4><div class="statusMessage"><?= htmlspecialchars($config->dynamic->loginInfoTitle, ENT_QUOTES); ?></div></h4>
        <div class="contractWrapper"><?= $config->dynamic->loginInfoHTML; ?></div>
    </div>
<?php endif; ?>
