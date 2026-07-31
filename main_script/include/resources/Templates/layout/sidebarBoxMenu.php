<div id="sidebarBoxMenu" class="sidebarBox   ">
    <div class="sidebarBoxBaseBox">
        <div class="baseBox baseBoxTop">
            <div class="baseBox baseBoxBottom">
                <div class="baseBox baseBoxCenter"></div>
            </div>
        </div>
    </div>
    <div class="sidebarBoxInnerBox">
        <div class="innerBox header noHeader">
        </div>
        <?php

        $file = strtolower(basename($_SERVER['PHP_SELF']));
        ?>
        <div class="innerBox content">
            <ul>
                <li class="first">
                    <a href="/"><?=T("Global", "Footer.HomePage"); ?></a>
                </li>
                <li<?=$file != 'support.php' && $file != 'activate.php' ? ' class="active"' : ''; ?>>
                    <a href="login.php"><?=T("Global", "Login"); ?></a>
                </li>

                <li<?=$file == 'activate.php' ? ' class="active"' : ''; ?>>
                    <a href="/"><?=T("Global", "Register"); ?></a>
                </li>
                <li>
                    <a href="/docs/">Documentation</a>
                </li>
                <li<?=$file == 'support.php' ? ' class="active"' : ''; ?>>
                    <a href="support.php"><?=T("Global", "Support"); ?></a>
                </li>
            </ul>
        </div>
        <div class="innerBox footer"></div>
    </div>
</div>
