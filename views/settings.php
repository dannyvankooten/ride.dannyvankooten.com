<?php
$pageStyle = <<<'CSS'
  body { font-family: system-ui, sans-serif; background: #f5f5f5; color: #222; padding: 24px 16px; }
  .page { max-width: 720px; margin: 0 auto; }
  h1 { font-size: 1.5rem; }

  /* Page header */
  .page-header { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
  .page-header__title { margin-bottom: 0; }
  .page-header__links { margin-left: auto; display: flex; align-items: center; gap: 12px; }
  .page-header__link { font-size: .8rem; color: #aaa; text-decoration: none; }
  .page-header__link:hover { color: #555; }

  /* Available-days picker */
  .day-picker { display: flex; gap: 8px; flex-wrap: wrap; }
  .day-picker__label { display: flex; flex-direction: column; align-items: center; gap: 4px;
    font-size: .75rem; color: #555; cursor: pointer; }
  .day-picker__label input[type=checkbox] { width: 20px; height: 20px; cursor: pointer; }
  .day-picker__label input:checked + span { font-weight: 700; color: #222; }

  /* Settings form */
  .settings-form { background: #fff; border-radius: 10px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  .settings-form__section { margin-bottom: 28px; }
  .settings-form__section:last-of-type { margin-bottom: 0; }
  .settings-form__section-title { font-size: .75rem; font-weight: 700; text-transform: uppercase; color: #888; letter-spacing: .05em; margin-bottom: 16px; }
  .settings-form__row { display: flex; align-items: flex-start; gap: 16px; margin-bottom: 16px; }
  .settings-form__row:last-child { margin-bottom: 0; }
  .settings-form__label { flex: 1; }
  .settings-form__label-text { display: block; font-size: .875rem; font-weight: 600; margin-bottom: 2px; }
  .settings-form__hint { font-size: .8rem; color: #888; line-height: 1.4; }
  .settings-form__input { width: 100px; padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px; font-size: .875rem; text-align: right; }
  .settings-form__input:focus { outline: none; border-color: #999; }
  .settings-form__footer { margin-top: 24px; padding-top: 20px; border-top: 1px solid #f0f0f0; }
  .settings-form__submit { background: #222; color: #fff; border: none; border-radius: 6px; padding: 10px 20px; font-size: .875rem; font-weight: 600; cursor: pointer; }
  .settings-form__submit:hover { background: #444; }
CSS;
?>
<div class="page">
  <div class="page-header">
    <h1 class="page-header__title">Settings</h1>
    <div class="page-header__links">
      <a class="page-header__link" href="/">← dashboard</a>
      <a class="page-header__link" href="/logout">sign out</a>
    </div>
  </div>

  <form class="settings-form" method="post" action="/settings">
    <div class="settings-form__section">
      <div class="settings-form__section-title">Weekly targets</div>

      <div class="settings-form__row">
        <label class="settings-form__label">
          <span class="settings-form__label-text">Available riding days</span>
          <span class="settings-form__hint">Which days of the week are you available to ride?</span>
        </label>
        <div class="day-picker">
          <?php
          $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
          $avail    = $settings['available_days'] ?? [0, 1, 2, 3, 4];
          foreach ($dayNames as $i => $name):
          ?>
          <label class="day-picker__label">
            <input type="checkbox" name="available_days[]" value="<?= $i ?>"<?= in_array($i, $avail, true) ? ' checked' : '' ?>>
            <span><?= $name ?></span>
          </label>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="settings-form__row">
        <label class="settings-form__label">
          <span class="settings-form__label-text">Target minutes</span>
          <span class="settings-form__hint">Total riding time to aim for each week.</span>
        </label>
        <input class="settings-form__input" type="number" name="target_minutes" min="0" max="1800" step="15" value="<?= (int) $settings['target_minutes'] ?>">
      </div>

<div class="settings-form__row">
        <label class="settings-form__label">
          <span class="settings-form__label-text">Long ride factor</span>
          <span class="settings-form__hint">How much longer the long ride slot is relative to an easy ride (e.g. 1.5 = 50% longer).</span>
        </label>
        <input class="settings-form__input" type="number" name="long_ride_factor" min="1" max="2.5" step="0.1" value="<?= $settings['long_ride_factor'] ?>">
      </div>
    </div>

    <div class="settings-form__section">
      <div class="settings-form__section-title">Hard-ride detection</div>

      <div class="settings-form__row">
        <label class="settings-form__label">
          <span class="settings-form__label-text">FTP (watts)</span>
          <span class="settings-form__hint">Used to detect hard workouts by power. Leave blank to disable.</span>
        </label>
        <input class="settings-form__input" type="number" name="ftp" min="1" max="1000" placeholder="—" value="<?= $settings['ftp'] !== null ? (int) $settings['ftp'] : '' ?>">
      </div>

      <div class="settings-form__row">
        <label class="settings-form__label">
          <span class="settings-form__label-text">Max heart rate (bpm)</span>
          <span class="settings-form__hint">Used to detect hard workouts by heart rate. Leave blank to disable.</span>
        </label>
        <input class="settings-form__input" type="number" name="max_heartrate" min="1" max="300" placeholder="—" value="<?= $settings['max_heartrate'] !== null ? (int) $settings['max_heartrate'] : '' ?>">
      </div>
    </div>

    <div class="settings-form__footer">
      <button class="settings-form__submit" type="submit">Save settings</button>
    </div>
  </form>
</div>
