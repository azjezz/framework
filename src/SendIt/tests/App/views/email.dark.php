<extends:sendit:builder subject="Demo Email"/>
<use:bundle path="sendit:bundle"/>

<email:attach path="{{ directory('root') . 'example.txt' }}" name="bootstrap.txt" />

<block:html>
    <p>Hello, {{ $name }}!</p>
</block:html>
