<?php
require_once __DIR__ . '/config.php';

$errors = [];
$old = ['name' => '', 'mobile' => '', 'email' => '', 'clinic' => '', 'message' => ''];
$sent = isset($_GET['sent']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $k => $_) { $old[$k] = trim($_POST[$k] ?? ''); }

    if (!empty($_POST['website'])) { exit; }

    if (str_len($old['name']) < 2)                             $errors['name'] = 'Enter your full name.';
    if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $old['mobile'])) $errors['mobile'] = 'Enter a valid mobile number.';
    if ($old['email'] !== '' && !filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if (!isset($branches[$old['clinic']]))                     $errors['clinic'] = 'Choose a clinic.';
    if (str_len($old['message']) < 10)                         $errors['message'] = 'Write at least a short message (10 characters).';

    if (!$errors) {
        try {
            require_once __DIR__ . '/includes/db.php';
            $ins = db()->prepare(
                "INSERT INTO contact_messages (name, mobile, email, branch_id, message)
                 VALUES (:name, :mobile, :email, (SELECT id FROM branches WHERE code = :clinic), :message)"
            );
            $ins->execute([
                ':name'    => $old['name'],
                ':mobile'  => $old['mobile'],
                ':email'   => $old['email'] !== '' ? $old['email'] : null,
                ':clinic'  => $old['clinic'],
                ':message' => $old['message'],
            ]);
            header('Location: contact.php?sent=1#message');
            exit;
        } catch (PDOException $ex) {
            error_log('MSCare DB (insert message): ' . $ex->getMessage());
            $errors['form'] = db_fail_text($ex, 'We could not save your message. Please call us instead.');
        }
    }
}

$page = 'contact'; $title = 'Contact';
include __DIR__ . '/includes/header.php';
?>

<section class="page-hero">
  <div class="container">
    <nav class="crumbs" aria-label="Breadcrumb">
      <a href="index.php">Home</a>
      <?= icon($ui['arrow'], 14) ?>
      <span aria-current="page">Contact us</span>
    </nav>
    <div class="eyebrow">Contact us</div>
    <h1>We’re here to help</h1>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="branch-grid">
      <?php foreach ($branches as $key => $b): ?>
        <article class="branch-card">
          <div class="branch-map">
            <iframe title="Map of <?= e($b['full']) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="<?= e(map_src($b['address'])) ?>"></iframe>
          </div>
          <div class="branch-body">
            <div class="eyebrow eyebrow-dark"><?= e($b['city']) ?> branch</div>
            <h2><?= e($b['full']) ?></h2>
            <div class="info-row">
              <?= icon($ui['pin'], 20) ?>
              <div><?php foreach ($b['lines'] as $line): ?><?= e($line) ?><br><?php endforeach; ?></div>
            </div>
            <div class="info-row">
              <?= icon($ui['phone'], 20) ?>
              <div><a class="phone" href="tel:<?= e($clinic['phone_tel']) ?>"><?= e($clinic['phone']) ?></a> <span class="tag">Mobile</span></div>
            </div>
            <div class="info-row">
              <?= icon($ui['clock'], 20) ?>
              <div>Open <?= e($b['days_text']) ?>.<br>Please call the branch to confirm clinic hours.</div>
            </div>
            <div class="btn-row branch-actions">
              <a class="btn btn-primary btn-pill" href="book.php?branch=<?= e($key) ?>">Book at <?= e($b['city']) ?></a>
              <a class="btn btn-outline btn-pill" href="<?= e(directions_url($b['address'])) ?>" target="_blank" rel="noopener"><?= icon($ui['compass'], 16) ?> Directions</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section-tint" id="message">
  <div class="container contact-grid">
    <div class="card form-card">
      <h2>Send us a message</h2>
      <?php if ($sent): ?>
        <div class="alert ok" role="status">Thank you. We received your message and will get back to you soon.</div>
      <?php endif; ?>
      <?php if (isset($errors['form'])): ?>
        <div class="alert bad" role="alert"><?= e($errors['form']) ?></div>
      <?php endif; ?>
      <form method="post" action="contact.php#message" novalidate>
        <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
          <label for="name">Full name</label>
          <input id="name" name="name" type="text" value="<?= e($old['name']) ?>" autocomplete="name" required>
          <?php if (isset($errors['name'])): ?><span class="err"><?= e($errors['name']) ?></span><?php endif; ?>
        </div>

        <div class="row">
          <div class="field<?= isset($errors['mobile']) ? ' has-error' : '' ?>">
            <label for="mobile">Mobile number</label>
            <input id="mobile" name="mobile" type="tel" value="<?= e($old['mobile']) ?>" autocomplete="tel" placeholder="09XX XXX XXXX" required>
            <?php if (isset($errors['mobile'])): ?><span class="err"><?= e($errors['mobile']) ?></span><?php endif; ?>
          </div>
          <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
            <label for="email">Email (optional)</label>
            <input id="email" name="email" type="email" value="<?= e($old['email']) ?>" autocomplete="email">
            <?php if (isset($errors['email'])): ?><span class="err"><?= e($errors['email']) ?></span><?php endif; ?>
          </div>
        </div>

        <div class="field<?= isset($errors['clinic']) ? ' has-error' : '' ?>">
          <label for="clinic">Preferred clinic</label>
          <select id="clinic" name="clinic" required>
            <option value="">Choose a clinic</option>
            <?php foreach ($branches as $key => $b): ?>
              <option value="<?= e($key) ?>"<?= $old['clinic'] === $key ? ' selected' : '' ?>><?= e($b['name']) ?> (<?= e($b['days_short']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <?php if (isset($errors['clinic'])): ?><span class="err"><?= e($errors['clinic']) ?></span><?php endif; ?>
        </div>

        <div class="field<?= isset($errors['message']) ? ' has-error' : '' ?>">
          <label for="message-text">Message</label>
          <textarea id="message-text" name="message" rows="5" required><?= e($old['message']) ?></textarea>
          <?php if (isset($errors['message'])): ?><span class="err"><?= e($errors['message']) ?></span><?php endif; ?>
        </div>

        <button type="submit" class="btn btn-teal btn-block">Send message</button>
      </form>
    </div>

    <aside class="contact-side">
      <div class="card">
        <h2>Contact details</h2>
        <dl class="details">
          <dt>Phone</dt><dd><a href="tel:<?= e($clinic['phone_tel']) ?>"><?= e($clinic['phone']) ?></a></dd>
          <dt>Facebook</dt><dd><?= e($clinic['facebook']) ?></dd>
        </dl>
      </div>
    </aside>
  </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
