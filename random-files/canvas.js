import utils from './utils'

const canvas = document.querySelector('canvas')
const c = canvas.getContext('2d')

canvas.width = innerWidth
canvas.height = innerHeight

let mouse = {
  x: innerWidth / 2,
  y: innerHeight / 2,
  m: 0
}

addEventListener('mousemove', (event) => {
  mouse.x = event.clientX
  mouse.y = event.clientY
  mouse.m = event.movementX
})

addEventListener('touchmove', (event) => {
  mouse.x = event.clientX
  mouse.y = event.clientY
  mouse.m = event.movementX
})

addEventListener('resize', () => {
  canvas.width = innerWidth
  canvas.height = innerHeight
  init()
})

addEventListener('orientationchange', () => {
  canvas.width = innerWidth
  canvas.height = innerHeight
  init()
});

// Objects
class Star {
  constructor(x, y, radius, color) {
    this.x = x
    this.y = y
    this.radius = radius
    this.color = color
    this.friction = 0.8
    this.gravity = 1
    this.velocity = {
      x: utils.randomIntFromRange(-10,10),
      y: 1
    }
  }

  draw() {
    c.save()
    c.beginPath()
    c.arc(this.x, this.y, this.radius, 0, Math.PI * 2, false)
    c.fillStyle = this.color
    c.shadowBlur = 20
    c.shadowColor = this.color
    c.fill()
    c.closePath()
    c.restore()
  }

  update() {
    this.draw()

    // When star hits base
    if( this.y + this.radius + this.velocity.y > canvas.height -  8 ){
      this.velocity.y = -this.velocity.y * this.friction
      this.shatter()
    }else{
      this.velocity.y += this.gravity
    }

    if( this.x + this.radius + this.velocity.x > canvas.width || this.x - this.radius <= 0){
      this.velocity.x = -this.velocity.x * this.friction
      this.shatter()
    }

    this.y += this.velocity.y
    this.x += this.velocity.x
  }

  shatter(){
    if(this.radius > 0){
      this.radius -= 3
      for (let i = 0; i < 3; i++) {
        miniStars.push(new MiniStar(this.x, this.y, 2))
      }
    }
  }
}

class MiniStar {
  constructor(x, y, radius, color){
    this.x = x
    this.y = y
    this.radius = radius
    this.color = color
    this.friction = 0.8
    this.gravity = 0.5
    this.velocity = {
      x: utils.randomIntFromRange(-5,5),
      y: utils.randomIntFromRange(-15,15)
    }
    this.ttl = 100
    this.opacity = 1
  }

  draw(){
    c.beginPath()
    c.arc(this.x, this.y, this.radius, 0, Math.PI * 2, false)
    c.fillStyle = `rgba(255,255,255,${this.opacity})`
    c.shadowBlur = 2
    c.fill()
    c.closePath()
  }

  update(){
    this.draw()

    // When star hits base
    if( this.y + this.radius + this.velocity.y > canvas.height - groundHeight ){
      this.velocity.y = -this.velocity.y * this.friction
    }else{
      this.velocity.y += this.gravity
    }

    this.y += this.velocity.y
    this.x += this.velocity.x
    this.ttl -= 1
    this.opacity -= 1 / this.ttl
  }
}

class BackgroundStar {
  constructor(x, y, radius, color) {
    this.x = x
    this.y = y
    this.radius = radius
    this.color = color
    this.friction = 0.8
    this.gravity = 1
    this.velocity = {
      x: utils.randomIntFromRange(-10,10),
      y: 1
    }
  }

  draw() {
    c.save()
    c.beginPath()
    c.arc(this.x, this.y, this.radius, 0, Math.PI * 2, false)
    c.fillStyle = this.color
    c.shadowBlur = 20
    c.shadowColor = this.color
    c.fill()
    c.closePath()
    c.restore()
  }

  update() {
    this.draw()
    this.x = this.x + .2
  }
}

function createMountainRange(mountainAmount, mountainHeight, mountainColor, xOffset){
  for (let i = 0; i < mountainAmount; i++) {
    const mountainWidth = canvas.width / mountainAmount
    c.save()
    c.beginPath()
    c.moveTo(i * mountainWidth, canvas.height)
    c.lineTo(i * mountainWidth + mountainWidth + 325 + xOffset, canvas.height)
    c.lineTo((i * mountainWidth + mountainWidth / 2) + xOffset, canvas.height - mountainHeight)
    c.lineTo(i * mountainWidth - 325 + xOffset, canvas.height)
    c.fillStyle = mountainColor
    c.fill()
    c.closePath()
    c.restore()
  }
}

// Implementation
let stars
let miniStars
let backgroundStars
let ticker
let randomSpawnRate = 75
let groundHeight = canvas.height * .2
const starStartRadius = 12;
const backgroundGradient = c.createLinearGradient(0, 0, 0, canvas.height);
backgroundGradient.addColorStop(0, '#171e26');
backgroundGradient.addColorStop(1, '#3f586b');

function init() {
  ticker = 0
  stars = []
  miniStars = []
  backgroundStars = []
  groundHeight = canvas.height * .2
  for (let i = 0; i < 100; i++) {
    const x = Math.random() * canvas.width
    const y = Math.random() * canvas.height
    const radius = Math.random() * 3
    backgroundStars.push(new BackgroundStar(x, y, radius, 'white'))
  }
}

// Animation Loop
function animate() {
  requestAnimationFrame(animate)
  c.clearRect(0, 0, canvas.width, canvas.height)
  c.fillStyle = backgroundGradient
  c.fillRect(0, 0, canvas.width, canvas.height)

  backgroundStars.forEach(backgroundStar => {
    backgroundStar.update()
    if(backgroundStar.x + backgroundStar.radius + backgroundStar.velocity.x > canvas.width){
      backgroundStar.x = -1
    }
  })

  createMountainRange(1, canvas.height - 150, '#384551', 100)
  createMountainRange(1, canvas.height - 150, '#384551', -600)
  createMountainRange(1, canvas.height - 150, '#384551', 400)
  c.fillStyle = 'white'
  c.fillText('Why Not Code, Coming soon!', mouse.x - 60, mouse.y - 15)
  createMountainRange(2, canvas.height - 200, '#2b3843', 0)
  createMountainRange(3, canvas.height - 400, '#26333e', 0)

  c.fillStyle = '#182028';
  c.fillRect(0, canvas.height - groundHeight, canvas.width, groundHeight)

   stars.forEach((star, index) => {
    star.update()
    if(star.radius === 0){
      stars.splice(index,1)
    }
  })

  miniStars.forEach((miniStar, index) => {
    miniStar.update()
    if(miniStar.ttl == 0){
      miniStars.splice(index, 1)
    }
  })

  ticker++
  if(ticker % randomSpawnRate == 0){
    const x = Math.max(starStartRadius, Math.random() * canvas.width - starStartRadius)
    stars.push(new Star(x, -100, starStartRadius, 'white'))
    randomSpawnRate = utils.randomIntFromRange(75, 200)
  }
}

init()
animate()
