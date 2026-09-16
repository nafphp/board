// Canvas celebration for tickets that reach a closing column.
// One shared overlay draws every burst so repeated drops stay cheap.
const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
// Additive blending glows on a dark board but burns out to white on a light one,
// so each theme gets its own blend mode and its own set of colours.
const glowColors = ['#ffd267', '#ff6b9a', '#4ad9b4', '#63c9ff', '#c3a2ff', '#fff4d6'];
const inkColors = ['#f0a500', '#ff3d71', '#0fb27f', '#2b8cff', '#7c5cff', '#e8365d'];
const gravity = 620;
const maxDelta = 1 / 30;

let canvas = null;
let context = null;
let running = false;
let lastFrame = 0;
let glow = false;
const sparks = [];
const shells = [];
const waves = [];

function theme() {
  const style = getComputedStyle(document.documentElement);
  glow = style.colorScheme.includes('dark');

  return style.getPropertyValue('--accent').trim() || '#6558e8';
}

function palette() {
  return glow ? glowColors : inkColors;
}

function resize() {
  if (!canvas) return;
  const ratio = Math.min(devicePixelRatio || 1, 2);
  canvas.width = Math.ceil(innerWidth * ratio);
  canvas.height = Math.ceil(innerHeight * ratio);
  canvas.style.width = innerWidth + 'px';
  canvas.style.height = innerHeight + 'px';
  context.setTransform(ratio, 0, 0, ratio, 0, 0);
}

function surface() {
  if (canvas) return canvas;
  canvas = document.createElement('canvas');
  canvas.className = 'fireworks-layer';
  canvas.setAttribute('aria-hidden', 'true');
  document.body.append(canvas);
  context = canvas.getContext('2d');
  resize();
  addEventListener('resize', resize, { passive: true });

  return canvas;
}

function random(min, max) {
  return min + Math.random() * (max - min);
}

function pick(list) {
  return list[(Math.random() * list.length) | 0];
}

function addSpark(x, y, speed, angle, options = {}) {
  sparks.push({
    x,
    y,
    vx: Math.cos(angle) * speed,
    vy: Math.sin(angle) * speed,
    age: 0,
    ttl: options.ttl ?? random(0.55, 1.15),
    size: options.size ?? random(1.4, 3),
    color: options.color ?? pick(palette()),
    drag: options.drag ?? 0.935,
    weight: options.weight ?? 1,
    confetti: options.confetti ?? false,
    spin: random(-9, 9),
    tilt: random(0, Math.PI),
  });
}

// A shell climbs away from the card and pops a moment later, so the burst reads as a firework.
function addShell(x, y, colour) {
  shells.push({
    x,
    y,
    vx: random(-70, 70),
    vy: random(-430, -320),
    age: 0,
    fuse: random(0.32, 0.46),
    color: colour,
    trail: 0,
  });
}

function explode(x, y, colour, count, power) {
  waves.push({ x, y, age: 0, ttl: 0.42, color: colour, radius: power * 0.07 });
  const twist = Math.random() * Math.PI;

  for (let index = 0; index < count; index += 1) {
    const angle = twist + (index / count) * Math.PI * 2 + random(-0.08, 0.08);
    const speed = power * random(0.45, 1);
    addSpark(x, y, speed, angle, {
      color: Math.random() < 0.6 ? colour : pick(palette()),
      ttl: random(0.6, 1.25),
    });
  }
  for (let index = 0; index < count / 3; index += 1) {
    addSpark(x, y, power * random(0.2, 0.6), random(0, Math.PI * 2), {
      confetti: true,
      size: random(3, 5.5),
      ttl: random(0.9, 1.5),
      drag: 0.86,
      weight: 0.55,
    });
  }
}

function step(delta) {
  for (let index = shells.length - 1; index >= 0; index -= 1) {
    const shell = shells[index];
    shell.age += delta;
    shell.vy += gravity * 0.45 * delta;
    shell.x += shell.vx * delta;
    shell.y += shell.vy * delta;
    shell.trail += delta;
    if (shell.trail > 0.016) {
      shell.trail = 0;
      addSpark(shell.x, shell.y, random(6, 34), random(0, Math.PI * 2), {
        color: shell.color,
        ttl: random(0.2, 0.36),
        size: random(1.3, 2.4),
        weight: 0.25,
      });
    }
    if (shell.age >= shell.fuse) {
      explode(shell.x, shell.y, shell.color, 24, 330);
      shells.splice(index, 1);
    }
  }
  for (let index = sparks.length - 1; index >= 0; index -= 1) {
    const spark = sparks[index];
    spark.age += delta;
    if (spark.age >= spark.ttl) {
      sparks.splice(index, 1);
      continue;
    }
    const friction = Math.pow(spark.drag, delta * 60);
    spark.vx *= friction;
    spark.vy = spark.vy * friction + gravity * spark.weight * delta;
    spark.x += spark.vx * delta;
    spark.y += spark.vy * delta;
    spark.tilt += spark.spin * delta;
  }
  for (let index = waves.length - 1; index >= 0; index -= 1) {
    waves[index].age += delta;
    if (waves[index].age >= waves[index].ttl) waves.splice(index, 1);
  }
}

function paint() {
  context.clearRect(0, 0, innerWidth, innerHeight);
  context.globalCompositeOperation = glow ? 'lighter' : 'source-over';

  for (const wave of waves) {
    const progress = wave.age / wave.ttl;
    const radius = wave.radius + progress * 46;
    const fade = (1 - progress) ** 2;
    const flash = context.createRadialGradient(wave.x, wave.y, 0, wave.x, wave.y, radius + 16);
    flash.addColorStop(0, wave.color);
    flash.addColorStop(1, 'transparent');
    context.globalAlpha = fade * 0.55;
    context.fillStyle = flash;
    context.beginPath();
    context.arc(wave.x, wave.y, radius + 16, 0, Math.PI * 2);
    context.fill();
    context.globalAlpha = fade * 0.75;
    context.strokeStyle = wave.color;
    context.lineWidth = Math.max(0.8, 5 * fade);
    context.beginPath();
    context.arc(wave.x, wave.y, radius, 0, Math.PI * 2);
    context.stroke();
  }
  for (const spark of sparks) {
    const life = 1 - spark.age / spark.ttl;
    const flicker = spark.confetti ? 1 : 0.7 + Math.abs(Math.sin(spark.age * 22)) * 0.3;
    context.globalAlpha = Math.min(1, life * 1.6) * flicker;
    context.fillStyle = spark.color;
    if (spark.confetti) {
      context.save();
      context.translate(spark.x, spark.y);
      context.rotate(spark.tilt);
      context.scale(1, Math.max(0.15, Math.abs(Math.cos(spark.tilt * 1.6))));
      context.fillRect(-spark.size / 2, -spark.size / 2, spark.size, spark.size * 1.6);
      context.restore();
      continue;
    }
    context.strokeStyle = spark.color;
    context.lineWidth = spark.size;
    context.lineCap = 'round';
    context.beginPath();
    context.moveTo(spark.x - spark.vx * 0.032, spark.y - spark.vy * 0.032);
    context.lineTo(spark.x, spark.y);
    context.stroke();
  }
  context.globalAlpha = 1;
  context.globalCompositeOperation = 'source-over';
}

function tick(time) {
  const delta = Math.min(maxDelta, (time - lastFrame) / 1000 || 0);
  lastFrame = time;
  step(delta);
  paint();
  if (sparks.length || shells.length || waves.length) {
    requestAnimationFrame(tick);
    return;
  }
  running = false;
  canvas.classList.remove('active');
}

function start() {
  canvas.classList.add('active');
  if (running) return;
  running = true;
  lastFrame = performance.now();
  requestAnimationFrame(tick);
}

// Fires a small firework over the element and resolves once the card stamp has played.
export function celebrate(element) {
  element.classList.remove('celebrating');
  void element.offsetWidth;
  element.classList.add('celebrating');
  setTimeout(() => element.classList.remove('celebrating'), 1400);
  if (reducedMotion.matches) return;

  const box = element.getBoundingClientRect();
  const x = box.left + box.width / 2;
  const y = box.top + box.height * 0.42;
  const colour = theme();
  surface();
  start();
  explode(x, y, colour, 54, 430);
  setTimeout(() => {
    if (!canvas) return;
    addShell(x - box.width * 0.26, y, pick(palette()));
    start();
  }, 120);
  setTimeout(() => {
    if (!canvas) return;
    addShell(x + box.width * 0.28, y, pick(palette()));
    start();
  }, 290);
}
