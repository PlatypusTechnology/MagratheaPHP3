<?php
    use Magrathea as Mag2;

    $bootstrapClass = Mag2\Bootstrap\Start::Instance();

    if(!$bootstrapClass->CheckSelf()) {
        ?>
        <span class="error">Bootstrap configuration error!</span><br/>
        To setup the Bootstrap class, don't forget to set the path:<br/>
<pre class="code">
$bootstrap = Magrathea\Bootstrap\Start::Instance();
$bootstrap->setPath(dirname(__FILE__));
$bootstrap->Load();
</pre>
        <?php
        return;
    }

    $bootstrapClass->ValidateStructure();

?>