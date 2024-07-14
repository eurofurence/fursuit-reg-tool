@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
<table
    border="0" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:separate;line-height:100%;margin-bottom:10px;margin-left:auto;margin-right:auto;"
>
    <tbody>
    <tr>
        <td
            align="center" bgcolor="#1f3c86" role="presentation"
            style="border:none;border-radius:5px;cursor:auto;mso-padding-alt:10px 25px;background:#1f3c86;text-align:center;"
            valign="center"
        >
            <a
                href="{{ $url }}"
                style="display:inline-block;background:#1f3c86;color:#ffffff;font-family:Arial, Helvetica Neue, Helvetica, sans-serif;font-size:16px;font-weight:normal;line-height:120%;margin:0;text-decoration:none;text-transform:none;padding:10px 25px;mso-padding-alt:0px;border-radius:5px;"
                target="_blank"
            >{{ $slot }}</a>
        </td>
    </tr>
    </tbody>
</table>
