<?php
/**
 * Created by PhpStorm.
 * User: Conakry
 * Date: 5/15/2016
 * Time: 2:26 PM
 */

$ids = buildFinalList();
echo json_encode($ids);

function buildFinalList() {
    $conn = dbConnect();
    $type = 'themoviedb';
    $input = json_decode(file_get_contents('php://input'), true);
    $genre = $input['genres'];
    $sources = $input['sources'];
    $page = 1;

    $result = array();

    while (count($result) <= 2) {

        $currentPage = generateList($genre, $page++);
        $idChecked = idCheck($conn, $currentPage, $type);
        $sourceChecked = sourceCheck($conn, $idChecked, $sources);
        $result = calcRating($sourceChecked, $result);
    }

    $conn->close();

    $filmIDs = array();

    foreach ($result as $key => $value) {
        array_push($filmIDs, $key);
    }

    return $filmIDs;
}

function generateList($genreID, $pageNum) {
    $usURL = "http://api.themoviedb.org/3/discover/movie?";
    $apiKey = "api_key=9372ef92347b5703a397967af35f6c1c";
    $genre = "&with_genres=" . $genreID;
    $page = "&page=" . $pageNum;
    $votes = "&vote_count.gte=50";
    $endURL = "&sort_by=vote_average.desc";
    $data1 = json_decode(file_get_contents($usURL.$apiKey.$genre.$page.$votes.$endURL), true);
    $data2 = $data1['results'];
    $ids = array();
    foreach ($data2 as $result) {
        array_push($ids, $result['id']);
    }
    return $ids;
}

function dbConnect() {
    $conn = new mysqli("localhost", "root", "", "movies");
    if ($conn->connect_error) {
        exit("Server connection error");
    }
    return $conn;
}

function idCheck($conn, $ids, $type) {

    $results = array();

    foreach ($ids as $id) {
        //database check
        $query = "SELECT * FROM `filmids` WHERE ".$type."=\"".$id."\"";
        $result = $conn->query($query);

        if ($result->num_rows == 0) {
            $guideCall = json_decode(file_get_contents
            ('http://api-public.guidebox.com/v1.43/US/rKs3n3vENgt7CQC6D2W8y8tQYtrVoaAx/search/movie/id/'
                .$type.'/'.$id), true);

            if (empty($guideCall) || empty($guideCall['imdb'])){
                continue;
            } else {
                $in = "INSERT INTO `filmids` (`imdb`, `themoviedb`, `guide`) VALUES (\""
                    .$guideCall["imdb"]."\", \"".$guideCall["themoviedb"]."\", \""
                    .$guideCall["id"]."\")";
                $conn->query($in);
                $out = "SELECT * FROM `filmids` WHERE ".$type."=\"".$id."\"";
                $result = $conn->query($out);
                array_push($results, $result->fetch_assoc());
            }
        } else {
            array_push($results, $result->fetch_assoc());
        }
    }

    return $results;
}

function sourceCheck($conn, $ids, $sources) {

    $validFilms = array();

    foreach ($ids as $film) {
        $query = "SELECT * FROM `sources` WHERE id=".$film['id'];
        $result = $conn->query($query);

        if ($result->num_rows == 0) {
            $addRow = "INSERT INTO `sources`(`id`) VALUE (".$film['id'].")";
            $conn->query($addRow);

            $guideCall = json_decode(file_get_contents
            ('http://api-public.guidebox.com/v1.43/US/rKs3n3vENgt7CQC6D2W8y8tQYtrVoaAx/movie/'
                . $film['guide']), true);

            $webSources = $guideCall['subscription_web_sources'];
            $trueSources = array();

            foreach ($webSources as $webSource) {
                array_push($trueSources, $webSource['source']);
            }

            foreach ($trueSources as $trueSource) {
                $query = "UPDATE `sources` SET `".$trueSource."` = \"".$trueSource."\" WHERE `id`=".$film['id'];
                $check = $conn->query($query);
                if ($check) {
                    continue;
                } else {
                    $conn->query("ALTER TABLE `sources` ADD `".$trueSource."` VARCHAR(255)");
                    $conn->query($query);
                }
            }

            if (count(array_diff($sources, $trueSources)) < count($sources)) {
                array_push($validFilms, $film);
            }

        } else {
            if (count(array_diff($sources, $result->fetch_assoc())) < count($sources)) {
                array_push($validFilms, $film);
            }
        }
    }
    return $validFilms;
}

function calcRating($films, $sorted) {

    foreach ($films as $film) {

        $OMDb = json_decode(file_get_contents("http://www.omdbapi.com/?i=".$film['imdb']."&tomatoes=true"), true);

        $rating = ($OMDb['Metascore']*0.25) + (($OMDb['imdbRating']*10)*0.2)
            + ($OMDb['tomatoMeter']*0.15) + ($OMDb['tomatoUserMeter']*0.4);

        $film['rating'] = $rating;

        $sorted[$film['imdb']] = $rating;
    }

    arsort($sorted);

    return $sorted;
}

