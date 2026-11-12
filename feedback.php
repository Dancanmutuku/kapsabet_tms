<?php
require_once 'includes/auth.php';
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        submit_feedback(db(), $_POST['trip'] !== '' ? (int)$_POST['trip'] : null, trim($_POST['reg']) ?: null, $_POST['category'], $_POST['rating'] !== '' ? (int)$_POST['rating'] : null, $_POST['message'], $_POST['phone'] ?? '', isset($_POST['consent']));
        $msg = 'Thank you. Your feedback has been received.';
    } catch (Throwable $t) { $err = $t->getMessage(); }
}
$title = 'Feedback'; include 'includes/header.php'; ?>
<h1>Feedback and complaints</h1>
<?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; if ($err): ?><div class="err"><?= e($err) ?></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<label>Vehicle registration number</label><input name="reg" data-reg placeholder="KDA 123X">
<label>Or trip number</label><input type="number" name="trip" min="1">
<label>Category</label><select name="category"><option>COMPLAINT</option><option>COMPLIMENT</option><option>SUGGESTION</option></select>
<label>Rating (1 to 5, optional)</label><input type="number" name="rating" min="1" max="5">
<label>Message</label><textarea name="message" rows="4" maxlength="1000" required></textarea>
<label>Phone number (optional, for a reply)</label><input name="phone" placeholder="07XX XXX XXX">
<label><input type="checkbox" name="consent"> I agree that my phone number is stored to follow up this feedback (Data Protection Act, 2019).</label>
<button>Submit</button></form>
<?php include 'includes/footer.php';
